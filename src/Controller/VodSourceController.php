<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CipherService;
use App\Service\Fs16MediaService;
use App\Service\MediaProviderService;
use App\Service\WiflixMediaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * VodSourceController
 *
 * Exposes a single endpoint that the frontend calls to retrieve streaming
 * sources for a movie or series. It proxies the request to the upstream provider
 * via the MediaProviderService and returns a normalised JSON array.
 *
 * Source names are anonymised before returning:
 *   – Sources from WiflixMediaService  → "Alpha 1", "Alpha 2", …
 *   – Sources from Fs16MediaService    → "Charlie 1", "Charlie 2", …
 *   – Sources from MediaProviderService → "Beta 1",  "Beta 2",  …
 *
 * The response body is AES-256-GCM encrypted so the raw URLs and provider
 * names are not visible in the browser Network inspector.
 *
 * Route: GET /api/vod/sources
 *
 * Query parameters:
 *   - tmdb_id    (required) integer — TMDB numeric ID
 *   - type       (required) string  — "movie" or "series"
 *   - lang       (optional) string  — ISO 639-1 code (e.g. "fr")
 *   - title_name (optional) string  — title for title-based provider lookup
 *   - season     (optional) integer — required if type="series"
 *   - episode    (optional) integer — required if type="series"
 */
#[Route('/api/vod')]
final class VodSourceController extends AbstractController
{
    private const DEFAULT_SOURCES = [
        'enabled'          => ['alpha' => true, 'beta' => true, 'charlie' => true],
        'disabled_servers' => [],
    ];

    public function __construct(
        private readonly MediaProviderService $mediaProviderService,
        private readonly WiflixMediaService   $wiflixMediaService,
        private readonly Fs16MediaService     $fs16MediaService,
        private readonly CipherService        $cipherService,
        private readonly string               $projectDir,
    ) {}

    #[Route('/sources', name: 'vod_sources', methods: ['GET'])]
    public function getSources(Request $request): Response
    {
        // ── Validate tmdb_id ────────────────────────────────────────────────
        $rawTmdbId = $request->query->get('tmdb_id');
        if ($rawTmdbId === null || !ctype_digit((string) $rawTmdbId)) {
            return $this->encryptedError('Missing or invalid required parameter: tmdb_id', 400);
        }
        $tmdbId = (int) $rawTmdbId;

        // ── Validate type ───────────────────────────────────────────────────
        $type = strtolower(trim((string) $request->query->get('type', '')));
        if (!in_array($type, ['movie', 'series'], true)) {
            return $this->encryptedError(
                'Missing or invalid required parameter: type (must be "movie" or "series")',
                400
            );
        }

        // ── Optional lang ────────────────────────────────────────────────────
        $lang = $request->query->get('lang');
        if ($lang !== null) {
            $lang = strtolower(trim($lang));
            if ($lang === '') {
                $lang = null;
            }
        }

        // ── Optional title_name (for title-based providers) ────────────────
        $titleName = $request->query->get('title_name');
        if ($titleName !== null) {
            $titleName = trim($titleName);
            if ($titleName === '') {
                $titleName = null;
            }
        }

        // ── Season / episode for series ──────────────────────────────────────
        $season  = null;
        $episode = null;
        if ($type === 'series') {
            $s = $request->query->get('season');
            $e = $request->query->get('episode');
            if ($s === null || $e === null) {
                return $this->encryptedError('Missing required parameters for series: season and episode', 400);
            }
            $season  = (int) $s;
            $episode = (int) $e;
        }

        // ── Read source settings ────────────────────────────────────────────
        $sourceSettings = $this->readVodSourcesSettings();
        $enabled        = $sourceSettings['enabled'];
        $disabledServers = array_map('strtolower', $sourceSettings['disabled_servers']);

        // ── Fetch from providers ─────────────────────────────────────────────
        $wiflixSources  = [];
        $charlieSources = [];
        $betaSources    = [];

        if ($titleName !== null && ($enabled['alpha'] ?? true)) {
            $wiflixSources = $this->wiflixMediaService->getSources($type, $titleName, $lang, $season, $episode);
        }

        if ($titleName !== null && ($enabled['charlie'] ?? true)) {
            $charlieSources = $this->fs16MediaService->getSources($type, $titleName, $lang, $season, $episode);
        }

        if ($enabled['beta'] ?? true) {
            $betaSources = $this->mediaProviderService->getSources($type, $tmdbId, $lang, $season, $episode);
        }

        // ── Tag server names before anonymisation ────────────────────────────
        foreach ($wiflixSources as &$src) {
            $src['server'] = $this->extractServerFromName($src['name'] ?? '');
        }
        unset($src);

        foreach ($betaSources as &$src) {
            $src['server'] = strtolower(trim($src['name'] ?? ''));
        }
        unset($src);

        foreach ($charlieSources as &$src) {
            $src['server'] = $this->extractServerFromName($src['name'] ?? '');
        }
        unset($src);

        // ── Anonymise names & encrypt embed URLs ─────────────────────────────
        $sources = array_merge(
            $this->anonymiseSources($wiflixSources, 'Alpha'),
            $this->anonymiseSources($betaSources,   'Beta'),
            $this->anonymiseSources($charlieSources, 'Charlie'),
        );

        // ── Filter out disabled servers ──────────────────────────────────────
        if ($disabledServers !== []) {
            $sources = array_values(array_filter($sources, function (array $src) use ($disabledServers): bool {
                $server = strtolower($src['server'] ?? '');

                if ($server === '') {
                    return true; // no server key — keep it
                }

                return !in_array($server, $disabledServers, true);
            }));
        }

        // ── Encrypt the entire response ──────────────────────────────────────
        $plainPayload = json_encode([
            'tmdb_id' => $tmdbId,
            'type'    => $type,
            'lang'    => $lang,
            'season'  => $season,
            'episode' => $episode,
            'count'   => count($sources),
            'sources' => $sources,
        ], JSON_UNESCAPED_UNICODE);

        $encrypted = $this->cipherService->encrypt($plainPayload);

        $response = new Response($encrypted);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('X-Encrypted', '1');

        return $response;
    }

    #[Route('/episodes', name: 'vod_episodes', methods: ['GET'])]
    public function getEpisodes(Request $request): JsonResponse
    {
        $rawTmdbId = $request->query->get('tmdb_id');
        if ($rawTmdbId === null || !ctype_digit((string) $rawTmdbId)) {
            return $this->json(['error' => 'Missing or invalid required parameter: tmdb_id'], 400);
        }
        $tmdbId = (int) $rawTmdbId;

        $episodes = $this->mediaProviderService->getEpisodes($tmdbId);

        return $this->json([
            'tmdb_id'  => $tmdbId,
            'count'    => count($episodes),
            'episodes' => $episodes,
        ]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Replace the provider-specific `name` with an anonymised label,
     * encrypt each source URL into an opaque token for the embed proxy,
     * and preserve the server key for disabled-server filtering.
     *
     * @param array<int, array{url:string,name:string,language:string,tag:?string,server:?string}> $sources
     */
    private function anonymiseSources(array $sources, string $prefix): array
    {
        $out = [];
        $n   = 1;
        foreach ($sources as $src) {
            $encryptedToken = $this->cipherService->encrypt($src['url']);
            $out[] = [
                'token'    => $encryptedToken,
                'name'     => $prefix . ' ' . $n,
                'language' => $src['language'] ?? 'fr',
                'tag'      => $this->displaySourceTag($src['tag'] ?? null, $src['language'] ?? 'fr'),
                'server'   => $src['server'] ?? null,
            ];
            $n++;
        }
        return $out;
    }

    /**
     * Extract the server/sub-provider name from a raw source name like
     * "Wiflix (Uqload)" or "Charlie (Vidzy)" — returns lowercase.
     */
    private function extractServerFromName(string $name): string
    {
        if (preg_match('/\(([^)]+)\)/', $name, $m)) {
            return strtolower(trim($m[1]));
        }

        return strtolower(trim($name));
    }

    private function readVodSourcesSettings(): array
    {
        $path = $this->projectDir . '/var/settings.json';

        if (!file_exists($path)) {
            return self::DEFAULT_SOURCES;
        }

        try {
            $content = file_get_contents($path);
            $data    = json_decode((string) $content, true);

            if (!is_array($data) || !isset($data['vod_sources'])) {
                return self::DEFAULT_SOURCES;
            }

            $vod = $data['vod_sources'];

            return [
                'enabled'          => array_merge(self::DEFAULT_SOURCES['enabled'], $vod['enabled'] ?? []),
                'disabled_servers' => $vod['disabled_servers'] ?? [],
            ];
        } catch (\Throwable) {
            return self::DEFAULT_SOURCES;
        }
    }

    private function displaySourceTag(?string $tag, string $language): string
    {
        $value = strtolower(trim($tag ?? ''));
        if (in_array($value, ['vf', 'vostfr', 'vfq', 'vff', 'vo'], true)) {
            return strtoupper($value);
        }

        $labels = [];
        foreach (preg_split('/[\s,;|]+/', strtolower(trim($language))) ?: [] as $lang) {
            $labels[] = match ($lang) {
                'fr', 'fre', 'fra', 'french', 'vf' => 'VF',
                'en', 'eng', 'english', 'vostfr' => 'VOSTFR',
                'vfq' => 'VFQ',
                'vff', 'truefrench' => 'VFF',
                'vo' => 'VO',
                default => strtoupper($lang),
            };
        }

        $labels = array_values(array_unique(array_filter($labels)));

        return $labels !== [] ? implode(', ', $labels) : 'VF';
    }

    private function encryptedError(string $message, int $status): Response
    {
        $response = new Response(json_encode(['error' => $message]), $status);
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
