<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CipherService;
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
 *   - title_name (optional) string  — title for Wiflix lookup
 *   - season     (optional) integer — required if type="series"
 *   - episode    (optional) integer — required if type="series"
 */
#[Route('/api/vod')]
final class VodSourceController extends AbstractController
{
    public function __construct(
        private readonly MediaProviderService $mediaProviderService,
        private readonly WiflixMediaService   $wiflixMediaService,
        private readonly CipherService        $cipherService,
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

        // ── Optional title_name (for Wiflix) ────────────────────────────────
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

        // ── Fetch from providers ─────────────────────────────────────────────
        $wiflixSources = [];
        if ($titleName !== null) {
            $wiflixSources = $this->wiflixMediaService->getSources($type, $titleName, $lang, $season, $episode);
        }

        $betaSources = $this->mediaProviderService->getSources($type, $tmdbId, $lang, $season, $episode);

        // ── Anonymise names & encrypt embed URLs ─────────────────────────────
        $sources = array_merge(
            $this->anonymiseSources($wiflixSources, 'Alpha'),
            $this->anonymiseSources($betaSources,   'Beta'),
        );

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
     * Replace the provider-specific `name` with an anonymised label and
     * encrypt each source URL into an opaque token for the embed proxy.
     *
     * @param array<int, array{url:string,name:string,language:string,tag:?string}> $sources
     */
    private function anonymiseSources(array $sources, string $prefix): array
    {
        $out = [];
        $n   = 1;
        foreach ($sources as $src) {
            $encryptedToken = $this->cipherService->encrypt($src['url']);
            $out[] = [
                'token'    => $encryptedToken,       // opaque — real URL hidden
                'name'     => $prefix . ' ' . $n,   // e.g. "Alpha 1", "Beta 2"
                'language' => $src['language'] ?? 'fr',
                'tag'      => $src['tag'] ?? null,
            ];
            $n++;
        }
        return $out;
    }

    private function encryptedError(string $message, int $status): Response
    {
        $response = new Response(json_encode(['error' => $message]), $status);
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
