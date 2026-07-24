<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WatchHistory;
use App\Repository\WatchHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/history')]
final class WatchHistoryController extends AbstractController
{
    private const DEFAULT_LIMIT = 12;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly WatchHistoryRepository $historyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'get_watch_history', methods: ['GET'])]
    public function getHistory(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $page  = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', self::DEFAULT_LIMIT);
        $limit = min(self::MAX_LIMIT, max(1, $limit));

        $total = $this->historyRepository->count(['user' => $user]);
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

        // Clamp page in case the caller asks for one past the end
        $page = min($page, $totalPages);

        $records = $this->historyRepository->findBy(
            ['user' => $user],
            ['updatedAt' => 'DESC'],
            $limit,
            ($page - 1) * $limit
        );

        $data = array_map(function (WatchHistory $h) {
            return [
                'tmdb_id'    => $h->getTmdbId(),
                'imdb_id'    => $h->getImdbId(),
                'title_name' => $h->getTitleName(),
                'poster_url' => $h->getPosterUrl(),
                'type'       => $h->getType(),
                'season'     => $h->getSeason(),
                'episode'    => $h->getEpisode(),
                'updated_at' => $h->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $records);

        return $this->json([
            'history'    => $data,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    #[Route('/mark', name: 'mark_watch_history', methods: ['POST'])]
    public function markHistory(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $content = json_decode($request->getContent(), true);
        if (!is_array($content)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $tmdbId = $content['tmdb_id'] ?? null;
        $type   = $content['type'] ?? null;

        if ($tmdbId === null || !in_array($type, ['movie', 'series'], true)) {
            return $this->json(['error' => 'Missing tmdb_id or invalid type'], 400);
        }

        $season  = null;
        $episode = null;

        if ($type === 'series') {
            $season  = $content['season'] ?? null;
            $episode = $content['episode'] ?? null;
            if ($season === null || $episode === null) {
                return $this->json(['error' => 'Missing season or episode for series'], 400);
            }
        }

        $imdbId    = $content['imdb_id'] ?? null;
        $titleName = $content['title_name'] ?? null;
        $posterUrl = $content['poster_url'] ?? null;

        // Check if history record already exists
        $record = $this->historyRepository->findOneBy([
            'user'   => $user,
            'tmdbId' => $tmdbId,
            'type'   => $type,
        ]);

        if (!$record) {
            $record = new WatchHistory();
            $record->setUser($user);
            $record->setTmdbId((int) $tmdbId);
            $record->setType($type);
        }

        $record->setSeason($season !== null ? (int) $season : null);
        $record->setEpisode($episode !== null ? (int) $episode : null);

        if ($imdbId) {
            $record->setImdbId($imdbId);
        }

        if ($titleName) {
            $record->setTitleName($titleName);
        }

        if ($posterUrl) {
            $record->setPosterUrl($posterUrl);
        }

        $record->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $this->json(['status' => 'success']);
    }
}