<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MediaProviderService
 *
 * Fetches available streaming sources for a movie or series from the upstream provider.
 */
final class MediaProviderService
{
    private const PROVIDER_API_SOURCE = 'https://huhu.to/mediaurl-source.json';
    private const PROVIDER_API_ITEM   = 'https://huhu.to/mediaurl-item.json';

    private const REQUEST_HEADERS = [
        'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept'       => 'application/json, */*',
        'Referer'      => 'https://huhu.to/',
        'Origin'       => 'https://huhu.to',
        'Content-Type' => 'application/json',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch all sources for a given title.
     *
     * @param string      $type      "movie" or "series"
     * @param int         $tmdbId    TMDB numeric ID
     * @param string|null $language  ISO 639-1 language code (e.g. "en")
     * @param int|null    $season    Season number (if series)
     * @param int|null    $episode   Episode number (if series)
     */
    public function getSources(
        string $type,
        int $tmdbId,
        ?string $language = null,
        ?int $season = null,
        ?int $episode = null
    ): array {
        $payload = [
            'language' => $language ?? 'de',
            'region'   => 'DE',
            'type'     => $type,
            'ids'      => ['tmdb_id' => (string) $tmdbId],
            'name'     => '',
        ];

        if ($type === 'series') {
            $payload['episode'] = [
                'ids'     => new \stdClass(), // forces {} instead of []
                'season'  => $season ?? 1,
                'episode' => $episode ?? 1,
            ];
        }

        $this->logger->info('[MediaProvider] Fetching sources', [
            'type'     => $type,
            'tmdb_id'  => $tmdbId,
            'season'   => $season,
            'episode'  => $episode,
            'language' => $language,
        ]);

        try {
            $response = $this->httpClient->request('POST', self::PROVIDER_API_SOURCE, [
                'headers' => self::REQUEST_HEADERS,
                'json'    => $payload,
                'timeout' => 15,
            ]);

            $raw = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('[MediaProvider] HTTP request failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->normaliseSources($raw, $language);
    }

    /**
     * Fetch the list of available episodes for a series from upstream.
     *
     * @param int $tmdbId
     * @return array<int, array{season: int, episode: int}>
     */
    public function getEpisodes(int $tmdbId): array
    {
        $payload = [
            'language' => 'de',
            'region'   => 'DE',
            'type'     => 'series',
            'ids'      => ['tmdb_id' => (string) $tmdbId],
            'name'     => '',
        ];

        try {
            $response = $this->httpClient->request('POST', self::PROVIDER_API_ITEM, [
                'headers' => self::REQUEST_HEADERS,
                'json'    => $payload,
                'timeout' => 15,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('[MediaProvider] HTTP item request failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $episodes = [];
        if (isset($data['episodes']) && is_array($data['episodes'])) {
            foreach ($data['episodes'] as $ep) {
                if (isset($ep['season'], $ep['episode'])) {
                    $episodes[] = [
                        'season'  => (int) $ep['season'],
                        'episode' => (int) $ep['episode'],
                    ];
                }
            }
        }

        return $episodes;
    }

    private function normaliseSources(array $raw, ?string $language): array
    {
        $results = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url  = isset($item['url'])  && is_string($item['url'])  ? trim($item['url'])  : '';
            $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';

            if ($url === '' || $name === '') {
                continue;
            }

            $langs = isset($item['languages']) && is_array($item['languages'])
                ? array_values(array_filter($item['languages'], 'is_string'))
                : [];

            $tag = isset($item['tag']) && is_string($item['tag']) ? trim($item['tag']) : null;

            if ($language !== null) {
                $needle = strtolower(trim($language));
                $match  = array_filter($langs, static fn (string $l): bool => strtolower($l) === $needle);

                if (empty($match)) {
                    continue;
                }
            }

            $langLabel = $language !== null
                ? strtolower(trim($language))
                : implode(', ', $langs);

            $results[] = [
                'url'      => $url,
                'name'     => $name,
                'language' => $langLabel ?: 'en',
                'tag'      => $tag,
            ];
        }

        return $results;
    }
}
