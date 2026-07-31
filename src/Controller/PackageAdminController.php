<?php

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\ChannelStream;
use App\Entity\Package;
use App\Repository\ChannelRepository;
use App\Repository\ChannelStreamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/admin/package')]
#[IsGranted('ROLE_ADMIN')]
class PackageAdminController extends AbstractController
{
    /**
     * Lightweight channel list for the package picker UI. Returns only the
     * scalar fields the form needs (no streams/packages hydration), so loading
     * thousands of channels is fast. Filters: source (IPTV|YouTube|LiveWatch),
     * category (name), search (name contains).
     */
    #[Route('/channels-picker', name: 'admin_package_channels_picker', methods: ['GET'])]
    public function channelsPicker(
        Request $request,
        ChannelRepository $channelRepository,
        ChannelStreamRepository $channelStreamRepository
    ): JsonResponse {
        $source = $request->query->get('source');
        $category = $request->query->get('category');
        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = $request->query->get('itemsPerPage', '20');
        $unpaginated = $itemsPerPage === 'all' || $itemsPerPage === '' || (int) $itemsPerPage <= 0;

        $sourceTypes = match ($source) {
            'IPTV' => ['IPTV'],
            'YouTube' => ['YouTube'],
            'LiveWatch' => ['cable', 'satellite', 'basic', 'premium', 'vod', 'LiveWatch'],
            default => null,
        };

        $qb = $channelRepository->createQueryBuilder('c')
            ->select('PARTIAL c.{id, name, logo, language}', 'cat.name AS category_name')
            ->leftJoin('c.category', 'cat')
            ->where('c.isActive = true');

        if ($sourceTypes !== null) {
            $qb->join('c.streams', 's')
                ->andWhere('LOWER(s.type) IN (:sourceTypes)')
                ->setParameter('sourceTypes', array_map('strtolower', $sourceTypes));
        }

        if ($category !== null && $category !== '') {
            $qb->andWhere('LOWER(cat.name) = LOWER(:category)')
                ->setParameter('category', $category);
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT c.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->addOrderBy('c.name', 'ASC');
        if (!$unpaginated) {
            $limit = min((int) $itemsPerPage, 1000);
            $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        }

        $rows = $qb->getQuery()->getResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row[0]->getId();
        }

        // Collect the display source labels for every channel in one query.
        $sourcesMap = [];
        if ($ids) {
            $streamRows = $channelStreamRepository->createQueryBuilder('st')
                ->select('IDENTITY(st.channel) AS cid, st.type')
                ->where('st.channel IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getArrayResult();

            foreach ($streamRows as $sr) {
                $cid = (string) $sr['cid'];
                $label = $this->sourceLabel((string) $sr['type']);
                if ($label !== null && !in_array($label, $sourcesMap[$cid] ?? [], true)) {
                    $sourcesMap[$cid][] = $label;
                }
            }
        }

        $member = [];
        foreach ($rows as $row) {
            $ch = $row[0];
            $member[] = [
                'id' => $ch->getId(),
                'iri' => '/api/channels/' . $ch->getId(),
                'name' => $ch->getName(),
                'logo' => $ch->getLogo(),
                'category' => $row['category_name'],
                'language' => $ch->getLanguage(),
                'sources' => $sourcesMap[(string) $ch->getId()] ?? [],
            ];
        }

        return $this->json([
            'member' => $member,
            'totalItems' => $total,
        ]);
    }

    private function sourceLabel(string $type): ?string
    {
        $t = strtolower(trim($type));
        if ($t === '') {
            return null;
        }
        if ($t === 'iptv') {
            return 'IPTV';
        }
        if ($t === 'youtube') {
            return 'YouTube';
        }
        if (in_array($t, ['cable', 'satellite', 'basic', 'premium', 'vod', 'livewatch'], true)) {
            return 'LiveWatch';
        }
        return null;
    }

    #[Route('/{id}/channels', name: 'admin_package_get_channels', methods: ['GET'])]
    public function getChannels(Package $package): JsonResponse
    {
        $items = [];

        foreach ($package->getChannels() as $channel) {
            $sources = [];
            foreach ($channel->getStreams() as $stream) {
                $type = strtolower(trim((string) $stream->getType()));
                if ($type !== '' && !in_array($type, $sources, true)) {
                    $sources[] = $type;
                }
            }

            $items[] = [
                'id' => $channel->getId(),
                'iri' => '/api/channels/' . $channel->getId(),
                'name' => $channel->getName(),
                'logo' => $channel->getLogo(),
                'category' => $channel->getCategory()?->getName(),
                'language' => $channel->getLanguage(),
                'sources' => $sources,
            ];
        }

        return $this->json([
            'member' => $items,
            'totalItems' => count($items),
        ]);
    }

    /**
     * Replaces the package's channel list. Body: { "channels": ["/api/channels/{id}", ...] }
     */
    #[Route('/{id}/channels', name: 'admin_package_set_channels', methods: ['PUT'])]
    public function setChannels(
        Package $package,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !array_key_exists('channels', $data) || !is_array($data['channels'])) {
            return $this->json(['error' => 'Request body must contain a "channels" array of IRIs'], 400);
        }

        foreach ($package->getChannels() as $channel) {
            $package->removeChannel($channel);
        }

        $added = 0;
        foreach ($data['channels'] as $iri) {
            if (!is_string($iri)) {
                continue;
            }
            $id = preg_replace('#/api/channels/?#', '', $iri);
            if ($id === '') {
                continue;
            }
            $channel = $channelRepository->find($id);
            if ($channel) {
                $package->addChannel($channel);
                $added++;
            }
        }

        $em->flush();

        return $this->json([
            'message' => 'Package channels updated',
            'count' => count($package->getChannels()),
        ]);
    }

    /**
     * Assigns every active channel matching the given filters to the package in
     * one DB round-trip. Body: { "source"?: "IPTV"|"YouTube"|"LiveWatch",
     * "category"?: string, "search"?: string }.
     */
    #[Route('/{id}/channels/bulk', name: 'admin_package_add_channels_bulk', methods: ['POST'])]
    public function addChannelsBulk(
        Package $package,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $source = $data['source'] ?? null;
        $category = isset($data['category']) ? (string) $data['category'] : null;
        $search = trim((string) ($data['search'] ?? ''));

        $sourceTypes = match ($source) {
            'IPTV' => ['IPTV'],
            'YouTube' => ['YouTube'],
            'LiveWatch' => ['cable', 'satellite', 'basic', 'premium', 'vod', 'LiveWatch'],
            default => null,
        };

        $ids = $channelRepository->findIdsByFilters($sourceTypes, $category, $search);

        $existing = [];
        foreach ($package->getChannels() as $channel) {
            $existing[(string) $channel->getId()] = true;
        }

        $added = 0;
        foreach ($ids as $id) {
            if (isset($existing[$id])) {
                continue;
            }
            $package->addChannel($em->getReference(Channel::class, Uuid::fromString($id)));
            $existing[$id] = true;
            $added++;
        }

        $em->flush();

        return $this->json([
            'message' => sprintf('Added %d channel(s)', $added),
            'added' => $added,
            'total' => count($package->getChannels()),
        ]);
    }
}
