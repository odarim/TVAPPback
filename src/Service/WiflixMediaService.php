<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMXPath;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WiflixMediaService
 *
 * Scrapes streaming sources from wiflix.tv and resolves direct video streams (e.g. Uqload).
 */
final class WiflixMediaService
{
    private const BASE_URL = 'https://www.wiflix.tv';
    
    private const REQUEST_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch all sources for a movie or series from wiflix.tv.
     */
    public function getSources(
        string $type,
        string $title,
        ?string $language = null,
        ?int $season = null,
        ?int $episode = null
    ): array {
        $searchQuery = trim($title);
        $this->logger->info('[Wiflix] Searching for title', [
            'type' => $type,
            'title' => $searchQuery,
            'language' => $language,
            'season' => $season,
            'episode' => $episode,
        ]);

        $watchPath = $this->searchTitle($searchQuery, $season);
        if ($watchPath === null) {
            $this->logger->info('[Wiflix] Title not found in search results');
            return [];
        }

        $this->logger->info('[Wiflix] Found watch page path', ['path' => $watchPath]);

        $html = '';
        if ($type === 'movie') {
            $html = $this->fetchUrl(self::BASE_URL . $watchPath);
        } else {
            // Mapping language: "en" -> "VOSTFR", "fr" -> "VF" (default)
            $wiflixLang = 'VF';
            if ($language !== null) {
                $cleanLang = strtolower(trim($language));
                if ($cleanLang === 'en' || $cleanLang === 'vostfr') {
                    $wiflixLang = 'VOSTFR';
                }
            }

            $epParam = ($episode !== null) ? $episode : 1;
            $episodeUrl = self::BASE_URL . $watchPath . '?language=' . $wiflixLang . '&episode=' . $epParam;
            
            $this->logger->info('[Wiflix] Fetching episode watch page', ['url' => $episodeUrl]);
            $html = $this->fetchUrl($episodeUrl);
        }

        if (trim($html) === '') {
            return [];
        }

        $rawSources = $this->extractEmbedLinks($html, $type);
        $this->logger->info('[Wiflix] Extracted raw embed sources count', ['count' => count($rawSources)]);

        $results = [];
        foreach ($rawSources as $src) {
            $embedUrl = $src['url'];
            $name = $src['name'];
            $langLabel = $src['language'];

            // If it is Uqload, resolve the direct .m3u8 stream!
            if (stripos($embedUrl, 'uqload') !== false) {
                $this->logger->info('[Wiflix] Attempting to resolve Uqload stream', ['url' => $embedUrl]);
                $directStream = $this->resolveUqloadStream($embedUrl);
                if ($directStream !== null) {
                    $results[] = [
                        'url' => $directStream,
                        'name' => 'Wiflix (' . $name . ')',
                        'language' => $langLabel,
                        'tag' => $this->displayLanguageTag($langLabel),
                    ];
                    // Still add the iframe fallback as well so the user has options
                    $results[] = [
                        'url' => $embedUrl,
                        'name' => 'Wiflix (' . $name . ')',
                        'language' => $langLabel,
                        'tag' => $this->displayLanguageTag($langLabel),
                    ];
                    continue;
                }
            }

            // Fallback: return embed URL as-is
            $results[] = [
                'url' => $embedUrl,
                'name' => 'Wiflix (' . $name . ')',
                'language' => $langLabel,
                'tag' => $this->displayLanguageTag($langLabel),
            ];
        }

        return $results;
    }

    /**
     * Search wiflix.tv and return the watch path (e.g. /watch/is-god-is).
     */
    private function searchTitle(string $title, ?int $season = null): ?string
    {
        $searchUrl = self::BASE_URL . '/search?keywords=' . urlencode($title);
        $html = $this->fetchUrl($searchUrl);

        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $anchors = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " mov-t ")]');

        if ($anchors === false || $anchors->length === 0) {
            return null;
        }

        // Expected match query e.g. "firecountrysaison4" or "isgodis"
        $matchTarget = $title;
        if ($season !== null) {
            $matchTarget .= ' saison ' . $season;
        }
        $cleanTarget = $this->cleanString($matchTarget);

        foreach ($anchors as $anchor) {
            $titleText = trim($anchor->textContent);
            $href = trim($anchor->getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $cleanTitle = $this->cleanString($titleText);
            
            // Check for exact matching or partial matching
            if (stripos($cleanTitle, $cleanTarget) !== false || stripos($cleanTarget, $cleanTitle) !== false) {
                return $href;
            }
        }

        // Fallback to the first item if no exact match found
        return $anchors->item(0)->getAttribute('href');
    }

    private function cleanString(string $str): string
    {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9]/', '', $str); // remove special characters
        return (string)$str;
    }

    /**
     * Extract embed links from movie/series page HTML.
     */
    private function extractEmbedLinks(string $html, string $type): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $sources = [];

        if ($type === 'movie') {
            // For movies, check inside #player-options
            $wrapper = $xpath->query('//div[@id="player-options"]');
            if ($wrapper !== false && $wrapper->length > 0) {
                $anchors = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " server-item ")]', $wrapper->item(0));
                foreach ($anchors as $anchor) {
                    $serverName = trim($anchor->textContent);
                    $dataSrc = trim($anchor->getAttribute('data-src'));

                    // Look for version options sibling
                    $dropdown = $xpath->query('./following-sibling::div[contains(concat(" ", normalize-space(@class), " "), " version-dropdown ")]', $anchor);
                    if ($dropdown !== false && $dropdown->length > 0) {
                        $options = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " version-option ")]', $dropdown->item(0));
                        foreach ($options as $option) {
                            $version = trim($option->getAttribute('data-version')); // VOSTFR or FRENCH
                            $url = trim($option->getAttribute('data-url'));

                            if ($url !== '') {
                                $sources[] = [
                                    'name' => $serverName . ' (' . $version . ')',
                                    'url' => $url,
                                    'language' => stripos($version, 'vost') !== false ? 'en' : 'fr',
                                ];
                            }
                        }
                    } else if ($dataSrc !== '') {
                        $sources[] = [
                            'name' => $serverName,
                            'url' => $dataSrc,
                            'language' => 'fr', // default to french if unspecified
                        ];
                    }
                }
            }
        } else {
            // For series episodes
            $serverItems = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " server-item ")]');
            foreach ($serverItems as $item) {
                $serverName = trim($item->textContent);
                $dataSrc = trim($item->getAttribute('data-src'));
                if ($dataSrc === '') {
                    $dataSrc = trim($item->getAttribute('data-url'));
                }

                if ($dataSrc !== '') {
                    // Check active language label from series page
                    $langLabel = 'fr';
                    // We can inspect parent lists to identify VOSTFR vs VF if needed,
                    // but for series episode pages wiflix filters the list, so all items belong to the requested lang
                    $sources[] = [
                        'name' => $serverName !== '' ? $serverName : 'Server',
                        'url' => $dataSrc,
                        'language' => $langLabel,
                    ];
                }
            }
        }

        return $sources;
    }

    /**
     * Extract the direct .m3u8 stream from a Uqload embed URL.
     */
    private function resolveUqloadStream(string $embedUrl): ?string
    {
        try {
            $html = $this->fetchUrl($embedUrl);
            if (trim($html) === '') {
                return null;
            }

            // Find eval packed script
            if (preg_match('/eval\s*\(\s*function\s*\(.*?}\s*\(\s*([\'"].*?[\'"])\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\'"].*?[\'"])\.split\s*\(\s*[\'"]\|[\'"]\s*\)/s', $html, $m)) {
                $unpacked = $this->unpackDeanEdwards($m[1], (int)$m[2], (int)$m[3], $m[4]);
                
                // Scan the unpacked JS for any .m3u8 files
                if (preg_match('/"(https?:\/\/[^"]+\.m3u8[^"]*)"/i', $unpacked, $hit)) {
                    return $hit[1];
                }
                if (preg_match('/\'(https?:\/\/[^\']+\.m3u8[^\']*)\'/i', $unpacked, $hit)) {
                    return $hit[1];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Wiflix] Error resolving Uqload stream', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Dean Edwards deobfuscator.
     */
    private function unpackDeanEdwards(string $p, int $a, int $c, string $k_str): string
    {
        // Strip outer quotes and unescape
        $p = substr($p, 1, -1);
        $p = str_replace(['\\\'', '\\"', '\\\\'], ['\'', '"', '\\'], $p);

        $k_str = substr($k_str, 1, -1);
        $k_str = str_replace(['\\\'', '\\"', '\\\\'], ['\'', '"', '\\'], $k_str);
        $k = explode('|', $k_str);

        // Deobfuscation loop
        for ($i = $c - 1; $i >= 0; $i--) {
            if (isset($k[$i]) && $k[$i] !== '') {
                $word = $this->baseEncode($i, $a);
                $p = preg_replace('/\b' . preg_quote($word, '/') . '\b/', $k[$i], $p);
            }
        }

        return $p;
    }

    private function baseEncode(int $num, int $base): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($base <= 36) {
            return base_convert((string)$num, 10, $base);
        }
        $r = $num % $base;
        $res = $chars[$r];
        $q = (int)floor($num / $base);
        while ($q > 0) {
            $r = $q % $base;
            $q = (int)floor($q / $base);
            $res = $chars[$r] . $res;
        }
        return $res;
    }

    private function displayLanguageTag(string $language): string
    {
        return match (strtolower(trim($language))) {
            'en', 'vostfr' => 'VOSTFR',
            'vfq' => 'VFQ',
            'vff', 'truefrench' => 'VFF',
            'vo' => 'VO',
            default => 'VF',
        };
    }

    private function fetchUrl(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => self::REQUEST_HEADERS,
                'timeout' => 10,
            ]);
            return $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('[Wiflix] HTTP Request failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
}
