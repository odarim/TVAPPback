<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MediaProviderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * VodSourceController
 *
 * Exposes a single endpoint that the frontend calls to retrieve streaming
 * sources for a movie or series. It proxies the request to the upstream provider
 * via the MediaProviderService and returns a normalised JSON array.
 *
 * Route: GET /api/vod/sources
 *
 * Query parameters:
 *   - tmdb_id  (required) integer — TMDB numeric ID (the `id` field from IDBM)
 *   - type     (required) string  — "movie" or "series"
 *   - lang     (optional) string  — ISO 639-1 code to filter (e.g. "en", "fr")
 *   - season   (optional) integer — required if type="series"
 *   - episode  (optional) integer — required if type="series"
 */
#[Route('/api/vod')]
final class VodSourceController extends AbstractController
{
    public function __construct(
        private readonly MediaProviderService $mediaProviderService,
    ) {}

    #[Route('/sources', name: 'vod_sources', methods: ['GET'])]
    public function getSources(Request $request): JsonResponse
    {
        $rawTmdbId = $request->query->get('tmdb_id');
        if ($rawTmdbId === null || !ctype_digit((string) $rawTmdbId)) {
            return $this->json(['error' => 'Missing or invalid required parameter: tmdb_id'], 400);
        }
        $tmdbId = (int) $rawTmdbId;

        $type = strtolower(trim((string) $request->query->get('type', '')));
        if (!in_array($type, ['movie', 'series'], true)) {
            return $this->json(['error' => 'Missing or invalid required parameter: type (must be "movie" or "series")'], 400);
        }

        $lang = $request->query->get('lang');
        if ($lang !== null) {
            $lang = strtolower(trim($lang));
            if ($lang === '') {
                $lang = null;
            }
        }

        $season  = null;
        $episode = null;
        if ($type === 'series') {
            $s = $request->query->get('season');
            $e = $request->query->get('episode');
            if ($s === null || $e === null) {
                return $this->json(['error' => 'Missing required parameters for series: season and episode'], 400);
            }
            $season  = (int) $s;
            $episode = (int) $e;
        }

        $sources = $this->mediaProviderService->getSources($type, $tmdbId, $lang, $season, $episode);

        return $this->json([
            'tmdb_id' => $tmdbId,
            'type'    => $type,
            'lang'    => $lang,
            'season'  => $season,
            'episode' => $episode,
            'count'   => count($sources),
            'sources' => $sources,
        ]);
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
}
