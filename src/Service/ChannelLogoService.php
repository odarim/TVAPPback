<?php

namespace App\Service;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves channel logos from public sources using a fallback chain:
 *
 *   1. iptv-org logo database (github.com/iptv-org/logos) — wide coverage,
 *      direct image URLs, no API key. The full index is downloaded once and
 *      cached on disk for 7 days so repeated runs are cheap.
 *   2. Wikimedia Commons search — free-text search for "<channel> logo",
 *      falls back gracefully when nothing is found.
 *
 * Wikimedia URLs are proxied through wsrv.nl (same as the frontend
 * optimizeLogoUrl helper) to avoid hotlink 403s and to force PNG output.
 */
class ChannelLogoService
{
    private const IPTV_INDEX_URL = 'https://iptv-org.github.io/api/channels.json';
    private const WIKIMEDIA_API = 'https://commons.wikimedia.org/w/api.php';
    private const INDEX_TTL = 7 * 86400; // 7 days

    private ?array $iptvIndex = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChannelRepository $channelRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Resolves the best logo URL for a channel name, trying iptv-org first
     * then Wikimedia Commons. Returns null when nothing is found.
     *
     * @param string      $name           Channel display name
     * @param string|null $country        Optional country hint (currently unused by sources)
     * @param bool        $withWikipedia  Set to false in bulk import loops to stay fast
     */
    public function resolve(string $name, ?string $country = null, bool $withWikipedia = true): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $logo = $this->iptvLookup($name);
        if ($logo) {
            return $logo;
        }

        if ($withWikipedia) {
            $logo = $this->wikipediaLookup($name);
            if ($logo) {
                return $logo;
            }
        }

        return null;
    }

    /**
     * Returns the list of channels that don't have a logo yet, so the
     * frontend can process them in chunks (avoids long-running requests).
     *
     * @return array<int, array{id: string, name: string, country: string|null}>
     */
    public function channelsWithoutLogos(): array
    {
        $rows = $this->channelRepository->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.country')
            ->where('c.logo IS NULL OR c.logo = :empty')
            ->setParameter('empty', '')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row) => [
            'id'      => (string) $row['id'],
            'name'    => $row['name'],
            'country' => $row['country'],
        ], $rows);
    }

    /**
     * Resolves and saves logos for a batch of channels. Each item is
     * {id, name, country}. The iptv-org index is fetched only once per run
     * and cached on disk, so repeated chunks are cheap.
     *
     * @param array<int, array{id: string, name: string, country?: string|null}> $items
     * @return array{processed: int, filled: int, failed: int}
     */
    public function processChunk(array $items): array
    {
        $stats = ['processed' => 0, 'filled' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $name = trim((string) ($item['name'] ?? ''));

            if (!$id || $name === '') {
                $stats['failed']++;
                continue;
            }

            $channel = $this->channelRepository->find($id);
            if (!$channel) {
                $stats['failed']++;
                continue;
            }

            // Skip channels that already have a logo (e.g. added meanwhile)
            if ($channel->getLogo()) {
                continue;
            }

            $stats['processed']++;
            $logo = $this->resolve($name, $item['country'] ?? null);
            if ($logo) {
                $channel->setLogo($logo);
                $stats['filled']++;
            } else {
                $stats['failed']++;
            }
        }

        $this->em->flush();

        return $stats;
    }

    /**
     * Batch-fetches logos for every channel. By default only fills channels
     * that are missing a logo; pass $overwrite=true to re-resolve all of them.
     *
     * @return array{checked: int, filled: int, failed: int}
     */
    public function backfill(bool $overwrite = false): array
    {
        $stats = ['checked' => 0, 'filled' => 0, 'failed' => 0];
        $batchCount = 0;

        foreach ($this->channelRepository->createQueryBuilder('c')->getQuery()->toIterable() as $channel) {
            $stats['checked']++;

            if (!$overwrite && $channel->getLogo()) {
                continue;
            }

            $logo = $this->resolve($channel->getName(), $channel->getCountry());
            if ($logo) {
                $channel->setLogo($logo);
                $stats['filled']++;
            } else {
                $stats['failed']++;
            }

            if (++$batchCount % 50 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();
        $this->em->clear();

        return $stats;
    }

    /**
     * Looks a channel up in the iptv-org index by (normalized) name.
     */
    private function iptvLookup(string $name): ?string
    {
        $index = $this->loadIptvIndex();
        if ($index === []) {
            return null;
        }

        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        if (isset($index[$normalized])) {
            return $index[$normalized];
        }

        // Retry without common qualifiers: "TNT HD" -> "TNT", "France 24 US" -> "France 24"
        foreach (['4k', 'uhd', 'fhd', 'hd', 'us', 'uk', 'tv', 'channel'] as $suffix) {
            $variant = preg_replace('/' . $suffix . '$/', '', $normalized);
            if ($variant !== $normalized && isset($index[$variant])) {
                return $index[$variant];
            }
        }

        return null;
    }

    /**
     * Searches Wikimedia Commons for "<channel> logo".
     */
    private function wikipediaLookup(string $name): ?string
    {
        $url = self::WIKIMEDIA_API . '?' . http_build_query([
            'action' => 'query',
            'generator' => 'search',
            'gsrsearch' => trim($name) . ' logo',
            'gsrnamespace' => 6,
            'gsrlimit' => 3,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime',
            'iiurlwidth' => 200,
            'format' => 'json',
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
            $data = $response->toArray(false);

            foreach ($data['query']['pages'] ?? [] as $page) {
                $imageInfo = $page['imageinfo'][0] ?? [];
                $mime = $imageInfo['mime'] ?? '';
                $thumbUrl = $imageInfo['thumburl'] ?? '';
                if ($thumbUrl && str_starts_with($mime, 'image/')) {
                    return $this->normalizeLogoUrl($thumbUrl);
                }
            }
        } catch (\Throwable $e) {
            // Source unreachable — keep the null fallback
        }

        return null;
    }

    /**
     * Downloads (once) and caches the iptv-org channel index, keyed by
     * normalized channel name -> logo URL. Cached on disk for 7 days.
     */
    private function loadIptvIndex(): array
    {
        if ($this->iptvIndex !== null) {
            return $this->iptvIndex;
        }

        $this->iptvIndex = [];
        $cacheFile = $this->cacheDir . '/iptv_channels_index.json';
        $content = null;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::INDEX_TTL) {
            $content = file_get_contents($cacheFile) ?: null;
        } else {
            try {
                $response = $this->httpClient->request('GET', self::IPTV_INDEX_URL, ['timeout' => 60]);
                $content = $response->getContent();
                if ($content !== '') {
                    @file_put_contents($cacheFile, $content);
                }
            } catch (\Throwable $e) {
                // Fall back to a stale cache if one exists
                if (is_file($cacheFile)) {
                    $content = file_get_contents($cacheFile) ?: null;
                }
            }
        }

        if (!$content) {
            return $this->iptvIndex;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->iptvIndex;
        }

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $this->normalizeName($entry['name'] ?? '');
            $logo = $entry['logo'] ?? '';
            if ($name === '' || !$logo || isset($this->iptvIndex[$name])) {
                continue;
            }
            $this->iptvIndex[$name] = $this->normalizeLogoUrl($logo);
        }

        return $this->iptvIndex;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        return preg_replace('/[^a-z0-9]+/', '', $name) ?? '';
    }

    /**
     * Proxies Wikimedia image URLs through wsrv.nl so the browser never hits
     * Wikimedia's hotlink protection, and forces a PNG output.
     */
    private function normalizeLogoUrl(string $url): string
    {
        if (preg_match('~(wikimedia\.org|wikipedia\.org)~', $url)) {
            return 'https://wsrv.nl/?url=' . rawurlencode($url) . '&output=png';
        }

        return $url;
    }
}
