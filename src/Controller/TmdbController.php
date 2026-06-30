<?php

namespace App\Controller;

use App\Service\TmdbService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Proxy controller for TMDB API endpoints.
 *
 * All routes are mounted under /api/tmdb and forward requests to TmdbService.
 * Errors from TMDB (4xx/5xx) are forwarded with their original status codes.
 *
 * @see https://developer.themoviedb.org/docs
 */
#[Route('/api/tmdb', name: 'api_tmdb_')]
class TmdbController extends AbstractController
{
    public function __construct(
        private TmdbService $tmdbService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search for movies, TV shows, people, or everything at once.
     *
     * GET /api/tmdb/search?type=movie|tv|person|multi&query=...&page=1
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $type  = $request->query->get('type', 'multi');
        $query = $request->query->get('query', '');
        $page  = (int) $request->query->get('page', 1);

        if (empty($query)) {
            return $this->json(['error' => 'The "query" parameter is required.'], 400);
        }

        return $this->tmdb(fn () => $this->tmdbService->search($type, $query, $page));
    }

    // -------------------------------------------------------------------------
    // Movie / TV details
    // -------------------------------------------------------------------------

    /**
     * Get details for a movie or TV show.
     *
     * GET /api/tmdb/{type}/{id}   (type = movie | tv)
     */
    #[Route('/{type}/{id}', name: 'details', methods: ['GET'],
        requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function details(string $type, int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getDetails($type, $id));
    }

    /**
     * Get trailers, teasers, and clips.
     *
     * GET /api/tmdb/{type}/{id}/videos
     */
    #[Route('/{type}/{id}/videos', name: 'videos', methods: ['GET'],
        requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function videos(string $type, int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getVideos($type, $id));
    }

    /**
     * Get posters, backdrops, and logos.
     *
     * GET /api/tmdb/{type}/{id}/images
     */
    #[Route('/{type}/{id}/images', name: 'images', methods: ['GET'],
        requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function images(string $type, int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getImages($type, $id));
    }

    /**
     * Get cast and crew credits.
     *
     * GET /api/tmdb/{type}/{id}/credits
     */
    #[Route('/{type}/{id}/credits', name: 'credits', methods: ['GET'],
        requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function credits(string $type, int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getCredits($type, $id));
    }

    /**
     * Get recommended titles.
     *
     * GET /api/tmdb/{type}/{id}/recommendations?page=1
     */
    #[Route('/{type}/{id}/recommendations', name: 'recommendations', methods: ['GET'],
        requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function recommendations(string $type, int $id, Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getRecommendations($type, $id, $page));
    }

    // -------------------------------------------------------------------------
    // TV Season
    // -------------------------------------------------------------------------

    /**
     * Get details for a specific TV season.
     *
     * GET /api/tmdb/tv/{id}/season/{season}
     */
    #[Route('/tv/{id}/season/{season}', name: 'tv_season', methods: ['GET'],
        requirements: ['id' => '\d+', 'season' => '\d+'])]
    public function season(int $id, int $season): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getSeason($id, $season));
    }

    // -------------------------------------------------------------------------
    // Trending & Discovery
    // -------------------------------------------------------------------------

    /**
     * Get trending content.
     *
     * GET /api/tmdb/trending/{type}/{window}
     *   type   = movie | tv | person | all
     *   window = day | week
     */
    #[Route('/trending/{type}/{window}', name: 'trending', methods: ['GET'],
        requirements: ['type' => 'movie|tv|person|all', 'window' => 'day|week'])]
    public function trending(string $type, string $window = 'week'): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getTrending($type, $window));
    }

    /**
     * Get popular movies or TV shows.
     *
     * GET /api/tmdb/{type}/popular?page=1
     */
    #[Route('/{type}/popular', name: 'popular', methods: ['GET'],
        requirements: ['type' => 'movie|tv'])]
    public function popular(string $type, Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getPopular($type, $page));
    }

    /**
     * Get top-rated movies or TV shows.
     *
     * GET /api/tmdb/{type}/top_rated?page=1
     */
    #[Route('/{type}/top_rated', name: 'top_rated', methods: ['GET'],
        requirements: ['type' => 'movie|tv'])]
    public function topRated(string $type, Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getTopRated($type, $page));
    }

    /**
     * Discover movies or TV shows with optional filters.
     *
     * GET /api/tmdb/discover/{type}?page=1&with_genres=28&sort_by=popularity.desc&...
     *   All extra query params are forwarded directly to TMDB.
     */
    #[Route('/discover/{type}', name: 'discover', methods: ['GET'],
        requirements: ['type' => 'movie|tv'])]
    public function discover(string $type, Request $request): JsonResponse
    {
        $page    = (int) $request->query->get('page', 1);
        $filters = $request->query->all();
        unset($filters['page']); // handled separately

        return $this->tmdb(fn () => $this->tmdbService->discover($type, $filters, $page));
    }

    // -------------------------------------------------------------------------
    // Genres
    // -------------------------------------------------------------------------

    /**
     * Get the official genre list for movies or TV shows.
     *
     * GET /api/tmdb/genres/{type}
     */
    #[Route('/genres/{type}', name: 'genres', methods: ['GET'],
        requirements: ['type' => 'movie|tv'])]
    public function genres(string $type): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getGenres($type));
    }

    // -------------------------------------------------------------------------
    // People
    // -------------------------------------------------------------------------

    /**
     * Get details for a person (actor, director, etc.).
     *
     * GET /api/tmdb/person/{id}
     */
    #[Route('/person/{id}', name: 'person', methods: ['GET'],
        requirements: ['id' => '\d+'])]
    public function person(int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getPerson($id));
    }

    /**
     * Get all movie + TV credits for a person.
     *
     * GET /api/tmdb/person/{id}/credits
     */
    #[Route('/person/{id}/credits', name: 'person_credits', methods: ['GET'],
        requirements: ['id' => '\d+'])]
    public function personCredits(int $id): JsonResponse
    {
        return $this->tmdb(fn () => $this->tmdbService->getPersonCredits($id));
    }

    // -------------------------------------------------------------------------
    // Movie-specific convenience endpoints
    // -------------------------------------------------------------------------

    /**
     * Get upcoming movies.
     *
     * GET /api/tmdb/movie/upcoming?page=1
     */
    #[Route('/movie/upcoming', name: 'movie_upcoming', methods: ['GET'])]
    public function upcoming(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getUpcoming($page));
    }

    /**
     * Get movies currently playing in theatres.
     *
     * GET /api/tmdb/movie/now_playing?page=1
     */
    #[Route('/movie/now_playing', name: 'movie_now_playing', methods: ['GET'])]
    public function nowPlaying(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getNowPlaying($page));
    }

    // -------------------------------------------------------------------------
    // TV-specific convenience endpoints
    // -------------------------------------------------------------------------

    /**
     * Get TV shows airing today.
     *
     * GET /api/tmdb/tv/airing_today?page=1
     */
    #[Route('/tv/airing_today', name: 'tv_airing_today', methods: ['GET'])]
    public function airingToday(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getAiringToday($page));
    }

    /**
     * Get TV shows currently on the air.
     *
     * GET /api/tmdb/tv/on_the_air?page=1
     */
    #[Route('/tv/on_the_air', name: 'tv_on_the_air', methods: ['GET'])]
    public function onTheAir(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);

        return $this->tmdb(fn () => $this->tmdbService->getOnTheAir($page));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a TMDB service call and wrap the result in a JsonResponse.
     * Catches RuntimeException (forwarded TMDB errors) and InvalidArgumentException
     * (bad parameters) and returns appropriate HTTP error responses.
     */
    private function tmdb(callable $call): JsonResponse
    {
        try {
            return $this->json($call());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            // Ensure the status code is a valid HTTP code (use 502 as fallback)
            $httpCode = ($code >= 400 && $code < 600) ? $code : 502;

            return $this->json(['error' => $e->getMessage()], $httpCode);
        }
    }
}
