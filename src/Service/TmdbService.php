<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Proxy service for The Movie Database (TMDB) REST API v3.
 *
 * All methods return the decoded JSON array from TMDB.
 * On HTTP or network errors a RuntimeException is thrown with the TMDB
 * status message (or a generic message) and the HTTP status code.
 *
 * @see https://developer.themoviedb.org/docs
 */
class TmdbService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private string $tmdbBearerToken,
        private string $tmdbBaseUrl,
    ) {
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search for movies, TV shows, people, or all at once.
     *
     * @param string $type  movie | tv | person | multi
     * @param string $query Search term
     * @param int    $page  Page number (1-based)
     */
    public function search(string $type, string $query, int $page = 1): array
    {
        $this->validateType($type, ['movie', 'tv', 'person', 'multi']);

        return $this->get("/search/{$type}", [
            'query' => $query,
            'page'  => $page,
        ]);
    }

    // -------------------------------------------------------------------------
    // Movie / TV details
    // -------------------------------------------------------------------------

    /**
     * Get details for a movie or TV show.
     *
     * @param string $type movie | tv
     * @param int    $id   TMDB content ID
     */
    public function getDetails(string $type, int $id): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/{$id}");
    }

    /**
     * Get trailers, teasers, clips and other videos.
     *
     * @param string $type movie | tv
     */
    public function getVideos(string $type, int $id): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/{$id}/videos");
    }

    /**
     * Get posters, backdrops, and logos.
     *
     * @param string $type movie | tv
     */
    public function getImages(string $type, int $id): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/{$id}/images");
    }

    /**
     * Get cast and crew credits.
     *
     * @param string $type movie | tv
     */
    public function getCredits(string $type, int $id): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/{$id}/credits");
    }

    /**
     * Get recommended titles (similar content).
     *
     * @param string $type movie | tv
     */
    public function getRecommendations(string $type, int $id, int $page = 1): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/{$id}/recommendations", ['page' => $page]);
    }

    // -------------------------------------------------------------------------
    // Discovery & Trending
    // -------------------------------------------------------------------------

    /**
     * Get trending titles.
     *
     * @param string $type   movie | tv | person | all
     * @param string $window day | week
     */
    public function getTrending(string $type, string $window = 'week'): array
    {
        $this->validateType($type, ['movie', 'tv', 'person', 'all']);
        if (!in_array($window, ['day', 'week'], true)) {
            throw new \InvalidArgumentException('window must be "day" or "week".');
        }

        return $this->get("/trending/{$type}/{$window}");
    }

    /**
     * Get popular movies or TV shows.
     *
     * @param string $type movie | tv
     */
    public function getPopular(string $type, int $page = 1): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/popular", ['page' => $page]);
    }

    /**
     * Get top-rated movies or TV shows.
     *
     * @param string $type movie | tv
     */
    public function getTopRated(string $type, int $page = 1): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/{$type}/top_rated", ['page' => $page]);
    }

    /**
     * Discover movies or TV shows with optional filters.
     *
     * Common filter keys for movies: with_genres, sort_by, year, vote_average.gte, etc.
     * Common filter keys for TV: with_genres, sort_by, first_air_date_year, etc.
     *
     * @param string $type    movie | tv
     * @param array  $filters Key-value pairs matching TMDB discover query params
     *
     * @see https://developer.themoviedb.org/reference/discover-movie
     * @see https://developer.themoviedb.org/reference/discover-tv
     */
    public function discover(string $type, array $filters = [], int $page = 1): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/discover/{$type}", array_merge($filters, ['page' => $page]));
    }

    // -------------------------------------------------------------------------
    // Genre lists
    // -------------------------------------------------------------------------

    /**
     * Get the official genre list for movies or TV shows.
     *
     * @param string $type movie | tv
     */
    public function getGenres(string $type): array
    {
        $this->validateType($type, ['movie', 'tv']);

        return $this->get("/genre/{$type}/list");
    }

    // -------------------------------------------------------------------------
    // People
    // -------------------------------------------------------------------------

    /**
     * Get details for a person (actor, director, etc.).
     */
    public function getPerson(int $id): array
    {
        return $this->get("/person/{$id}");
    }

    /**
     * Get all credits (movies + TV) for a person.
     */
    public function getPersonCredits(int $id): array
    {
        return $this->get("/person/{$id}/combined_credits");
    }

    // -------------------------------------------------------------------------
    // TV-specific
    // -------------------------------------------------------------------------

    /**
     * Get details for a specific TV season.
     */
    public function getSeason(int $seriesId, int $seasonNumber): array
    {
        return $this->get("/tv/{$seriesId}/season/{$seasonNumber}");
    }

    /**
     * Get TV shows airing today.
     */
    public function getAiringToday(int $page = 1): array
    {
        return $this->get('/tv/airing_today', ['page' => $page]);
    }

    /**
     * Get TV shows currently on the air.
     */
    public function getOnTheAir(int $page = 1): array
    {
        return $this->get('/tv/on_the_air', ['page' => $page]);
    }

    // -------------------------------------------------------------------------
    // Movie-specific
    // -------------------------------------------------------------------------

    /**
     * Get upcoming movies.
     */
    public function getUpcoming(int $page = 1): array
    {
        return $this->get('/movie/upcoming', ['page' => $page]);
    }

    /**
     * Get movies currently playing in theatres.
     */
    public function getNowPlaying(int $page = 1): array
    {
        return $this->get('/movie/now_playing', ['page' => $page]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request to the TMDB API.
     *
     * @param string $path         Relative path, e.g. "/movie/550"
     * @param array  $queryParams  Additional query parameters
     *
     * @throws \RuntimeException On HTTP error or transport failure
     */
    private function get(string $path, array $queryParams = []): array
    {
        ksort($queryParams);
        $cacheKey = 'tmdb_' . md5($path . serialize($queryParams));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($path, $queryParams) {
            $item->expiresAfter(3600); // 1 hour expiration

            try {
                $response = $this->httpClient->request('GET', $this->tmdbBaseUrl . $path, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->tmdbBearerToken,
                        'Accept'        => 'application/json',
                    ],
                    'query' => $queryParams,
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray(false); // false = don't throw on 4xx/5xx

                if ($statusCode >= 400) {
                    $message = $data['status_message'] ?? 'TMDB API error.';
                    throw new \RuntimeException($message, $statusCode);
                }

                return $data;
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException('Could not reach TMDB API: ' . $e->getMessage(), 503);
            }
        });
    }

    /**
     * Validate that $type is one of the allowed values.
     *
     * @param string   $type    Value to check
     * @param string[] $allowed Allowed values
     *
     * @throws \InvalidArgumentException
     */
    private function validateType(string $type, array $allowed): void
    {
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid type "%s". Allowed: %s.', $type, implode(', ', $allowed))
            );
        }
    }
}
