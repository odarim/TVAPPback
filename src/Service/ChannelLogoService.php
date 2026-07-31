<?php

namespace App\Service;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves channel logos from public sources using a fallback chain:
 *
 *   1. iptv-org logo database (iptv-org.github.io/api/logos.json joined with
 *      channels.json) — wide coverage, no API key. The combined name -> url
 *      index is downloaded once and cached on disk for 7 days.
 *   2. Wikimedia Commons search — free-text search for "<channel> logo",
 *      falls back gracefully when nothing is found.
 *
 * Wikimedia URLs are proxied through wsrv.nl (same as the frontend
 * optimizeLogoUrl helper) to avoid hotlink 403s and to force PNG output.
 */
class ChannelLogoService
{
    private const IPTV_CHANNELS_URL = 'https://iptv-org.github.io/api/channels.json';
    private const IPTV_LOGOS_URL = 'https://iptv-org.github.io/api/logos.json';
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
            // Bulk mode: iptv-org only. The Wikipedia fallback does one slow
            // HTTP call per channel and makes batches long enough to time out /
            // expire the JWT mid-batch. Use the per-channel fetch-logo endpoint
            // when you need a Wikipedia logo for a specific channel.
            $logo = $this->resolve($name, $item['country'] ?? null, false);
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
     * Loads the iptv-org logo index (normalized channel name -> logo URL).
     * The compact name -> url map is cached on disk for 7 days; only a cache
     * miss downloads the two ~10MB source files and rebuilds it.
     */
    private function loadIptvIndex(): array
    {
        if ($this->iptvIndex !== null) {
            return $this->iptvIndex;
        }

        $this->iptvIndex = [];
        $cacheFile = $this->cacheDir . '/channel_logos_index.json';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::INDEX_TTL) {
            $cached = json_decode(file_get_contents($cacheFile) ?: '', true);
            if (is_array($cached)) {
                return $this->iptvIndex = $cached;
            }
        }

        $index = $this->buildIptvIndex();
        if ($index !== []) {
            $this->iptvIndex = $index;
            @file_put_contents($cacheFile, json_encode($index));
        }

        return $this->iptvIndex;
    }

    /**
     * Builds the normalized name -> logo URL map by joining the iptv-org
     * channels list (name -> id) with the logos list (id -> url). Prefers
     * in-use PNG/JPEG logos over SVG and stores one entry per channel.
     */
    private function buildIptvIndex(): array
    {
        try {
            $channelsContent = $this->httpClient->request('GET', self::IPTV_CHANNELS_URL, ['timeout' => 90])->getContent();
            $logosContent = $this->httpClient->request('GET', self::IPTV_LOGOS_URL, ['timeout' => 90])->getContent();
        } catch (\Throwable $e) {
            return [];
        }

        // Decode as objects (lighter than assoc arrays for these big files)
        $channels = json_decode($channelsContent, false);
        $logos = json_decode($logosContent, false);
        if (!is_array($channels) || !is_array($logos)) {
            return [];
        }

        // 1. Best logo URL per channel id
        $bestById = [];
        foreach ($logos as $logo) {
            if (!isset($logo->channel, $logo->url) || !$logo->url) {
                continue;
            }
            $id = $logo->channel;
            $format = strtoupper((string) ($logo->format ?? ''));
            $formatPrio = match (true) {
                $format === 'PNG' => 3,
                str_contains($format, 'JPEG'), $format === 'JPG' => 2,
                $format === 'SVG' => 1,
                default => 0,
            };
            $score = (($logo->in_use ?? false) ? 1 : 0) * 100 + $formatPrio;

            if (!isset($bestById[$id]) || $score > $bestById[$id]['score']) {
                $bestById[$id] = ['url' => $logo->url, 'score' => $score];
            }
        }
        unset($logos);

        // 2. Map channel names to those URLs
        $nameUrl = [];
        foreach ($channels as $ch) {
            $name = $this->normalizeName($ch->name ?? '');
            if ($name === '' || !isset($bestById[$ch->id])) {
                continue;
            }
            if (!isset($nameUrl[$name])) {
                $nameUrl[$name] = $this->normalizeLogoUrl($bestById[$ch->id]['url']);
            }
        }
        unset($channels, $bestById);

        return $nameUrl;
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
