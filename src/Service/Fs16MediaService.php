<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMXPath;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scrapes streaming sources from fs16.lol / French Stream.
 */
final class Fs16MediaService
{
    private const BASE_URL = 'https://fs16.lol';

    private const REQUEST_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json,*/*;q=0.8',
        'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Origin' => self::BASE_URL,
        'Referer' => self::BASE_URL . '/',
    ];

    private const PLAYER_LABELS = [
        'vidzy' => 'Vidzy',
        'uqload' => 'Uqload',
        'dood' => 'Dood',
        'voe' => 'Voe',
        'filmoon' => 'Filmoon',
        'netu' => 'Netu',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function getSources(
        string $type,
        string $title,
        ?string $language = null,
        ?int $season = null,
        ?int $episode = null
    ): array {
        $searchTitle = trim($title);
        if ($type === 'series' && $season !== null) {
            $searchTitle .= ' saison ' . $season;
        }

        $this->logger->info('[Fs16] Searching for title', [
            'type' => $type,
            'title' => $searchTitle,
            'language' => $language,
            'season' => $season,
            'episode' => $episode,
        ]);

        $result = $this->searchTitle($searchTitle, $type);
        if ($result === null) {
            $this->logger->info('[Fs16] Title not found in search results');
            return [];
        }

        if ($type === 'movie') {
            return $this->getMovieSources($result['newsId']);
        }

        return $this->getSeriesSources($result['newsId'], $language, $episode ?? 1);
    }

    /**
     * @return array{path:string,title:string,newsId:string}|null
     */
    private function searchTitle(string $title, string $type): ?array
    {
        $html = $this->request('POST', self::BASE_URL . '/engine/ajax/search.php', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'body' => http_build_query(['query' => $title, 'page' => 1]),
        ]);

        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $items = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " search-item ")]');
        if ($items === false || $items->length === 0) {
            return null;
        }

        $cleanTarget = $this->cleanString($title);
        $fallback = null;

        foreach ($items as $item) {
            $onclick = $item->getAttribute('onclick');
            if (!preg_match('/location\.href\s*=\s*[\'"]([^\'"]+)[\'"]/', $onclick, $match)) {
                continue;
            }

            $path = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " search-title ")]', $item);
            $resultTitle = $titleNode !== false && $titleNode->length > 0 ? trim($titleNode->item(0)->textContent) : '';

            if (!preg_match('/\/(\d{2,})-/', $path, $idMatch)) {
                continue;
            }

            $result = [
                'path' => $path,
                'title' => $resultTitle,
                'newsId' => $idMatch[1],
            ];

            if ($fallback === null && $this->matchesType($path, $resultTitle, $type)) {
                $fallback = $result;
            }

            $cleanResult = $this->cleanString($resultTitle);
            if ($this->matchesType($path, $resultTitle, $type)
                && (str_contains($cleanResult, $cleanTarget) || str_contains($cleanTarget, $cleanResult))
            ) {
                return $result;
            }
        }

        return $fallback;
    }

    private function getMovieSources(string $newsId): array
    {
        $json = $this->fetchUrl(self::BASE_URL . '/engine/ajax/film_api.php?id=' . rawurlencode($newsId));
        $data = $this->decodeJson($json);
        if (!is_array($data) || !isset($data['players']) || !is_array($data['players'])) {
            return [];
        }

        $sources = [];
        foreach ($data['players'] as $player => $versions) {
            if ($player === 'premium' || !is_array($versions)) {
                continue;
            }

            foreach ($versions as $version => $url) {
                if (!is_string($url) || trim($url) === '') {
                    continue;
                }

                $sources[] = $this->formatSource($player, $version, $url);
            }
        }

        return $sources;
    }

    private function getSeriesSources(string $newsId, ?string $language, int $episode): array
    {
        $data = $this->fetchSeriesData($newsId);
        if ($data === null) {
            return [];
        }

        $episodeKey = (string) $episode;
        $sources = [];

        foreach ($this->seriesVersionsFromLanguage($language) as $version) {
            if (!isset($data[$version][$episodeKey]) || !is_array($data[$version][$episodeKey])) {
                continue;
            }

            foreach ($data[$version][$episodeKey] as $player => $url) {
                if ($player === 'premium' || !is_string($url) || trim($url) === '') {
                    continue;
                }

                $sources[] = $this->formatSource($player, $version, $url);
            }
        }

        return $sources;
    }

    private function fetchSeriesData(string $newsId): ?array
    {
        $paths = [
            '/static/series/' . $newsId . '.js',
            '/data/eps_' . $newsId . '.txt',
            '/ep-data.php?id=' . rawurlencode($newsId) . '&format=js',
        ];

        foreach ($paths as $path) {
            $json = $this->fetchUrl(self::BASE_URL . $path);
            $data = $this->decodeJson($json);
            if (is_array($data) && (isset($data['vf']) || isset($data['vostfr']) || isset($data['vo']))) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @return array{url:string,name:string,language:string,tag:string}
     */
    private function formatSource(string $player, string $version, string $url): array
    {
        $version = strtolower($version);
        $language = match ($version) {
            'vostfr', 'vo' => 'en',
            default => 'fr',
        };

        if ($player === 'netu' && !str_starts_with($url, 'http')) {
            $url = 'https://1.multiup.us/player/embed_player.php?vid=' . rawurlencode($url) . '&autoplay=yes';
        }

        $label = self::PLAYER_LABELS[strtolower($player)] ?? ucfirst($player);

        return [
            'url' => $url,
            'name' => 'Charlie (' . $label . ')',
            'language' => $language,
            'tag' => $this->displayVersionTag($version),
        ];
    }

    private function displayVersionTag(string $version): string
    {
        return match (strtolower(trim($version))) {
            'vostfr' => 'VOSTFR',
            'vfq' => 'VFQ',
            'vff' => 'VFF',
            'vo' => 'VO',
            default => 'VF',
        };
    }

    /**
     * @return list<string>
     */
    private function seriesVersionsFromLanguage(?string $language): array
    {
        $language = strtolower(trim((string) $language));
        if ($language === '') {
            return ['vf', 'vostfr', 'vo'];
        }

        return match ($language) {
            'en', 'vostfr' => ['vostfr'],
            'vo' => ['vo'],
            default => ['vf'],
        };
    }

    private function matchesType(string $path, string $title, string $type): bool
    {
        $haystack = strtolower($path . ' ' . $title);
        if ($type === 'series') {
            return str_contains($haystack, 'saison');
        }

        return !str_contains($haystack, 'saison');
    }

    private function cleanString(string $str): string
    {
        $str = strtolower(trim($str));
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str;
        return (string) preg_replace('/[^a-z0-9]/', '', $str);
    }

    private function decodeJson(string $json): mixed
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('[Fs16] JSON decode failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchUrl(string $url): string
    {
        return $this->request('GET', $url);
    }

    private function request(string $method, string $url, array $options = []): string
    {
        try {
            $options['headers'] = array_merge(self::REQUEST_HEADERS, $options['headers'] ?? []);
            $options['timeout'] ??= 10;

            $response = $this->httpClient->request($method, $url, $options);
            return $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('[Fs16] HTTP request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
