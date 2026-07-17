<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMXPath;

/**
 * SourceExtractorService
 *
 * Parses an HTML page and extracts streaming sources from a `<ul class="links">` list.
 *
 * This service is stateless and uses only native DOMDocument + DOMXPath — no external
 * libraries (Symfony DomCrawler, Simple HTML DOM, etc.) are used, and no regular
 * expressions are involved.
 *
 * Expected HTML structure:
 * <ul class="links">
 *     <li>
 *         <a class="link-row" href="https://example.com/video">
 *             <span class="link-name">Server C1</span>
 *             <span class="link-lang" title="EN">🇬🇧</span>
 *         </a>
 *     </li>
 * </ul>
 */
final class SourceExtractorService
{
    /**
     * Parse the provided HTML and return streaming sources whose language matches the
     * requested language code (case-insensitive comparison on the `title` attribute of
     * `.link-lang`).
     *
     * @param string $html     Raw HTML content of the page.
     * @param string $language Language code to filter by (e.g. "EN", "FR", "DE").
     *
     * @return array<int, array{server: string, language: string, url: string}> Matching sources.
     */
    public function extractSources(string $html, string $language): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument();

        // Suppress parse warnings for malformed HTML
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        /*
         * Locate every <a class="link-row"> that lives inside a <ul class="links">.
         *
         * XPath strategy:
         *   - contains(@class, "links")  — handles multi-class attributes
         *   - descendant::a[contains(@class, "link-row")]
         */
        /** @var \DOMNodeList<\DOMElement> $anchors */
        $anchors = $xpath->query(
            '//ul[contains(concat(" ", normalize-space(@class), " "), " links ")]'
            . '//a[contains(concat(" ", normalize-space(@class), " "), " link-row ")]'
        );

        if ($anchors === false || $anchors->length === 0) {
            return [];
        }

        $requestedLang = strtolower(trim($language));
        $results       = [];

        foreach ($anchors as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));

            // Skip entries with no href
            if ($href === '') {
                continue;
            }

            // Read server name from .link-name
            $serverNode = $xpath->query(
                './/span[contains(concat(" ", normalize-space(@class), " "), " link-name ")]',
                $anchor
            );

            $server = ($serverNode !== false && $serverNode->length > 0)
                ? trim((string) $serverNode->item(0)?->textContent)
                : '';

            if ($server === '') {
                continue;
            }

            // Read language from .link-lang[title]
            $langNode = $xpath->query(
                './/span[contains(concat(" ", normalize-space(@class), " "), " link-lang ")]',
                $anchor
            );

            if ($langNode === false || $langNode->length === 0) {
                continue;
            }

            /** @var \DOMElement $langEl */
            $langEl       = $langNode->item(0);
            $langAttribute = trim((string) $langEl->getAttribute('title'));

            if ($langAttribute === '') {
                continue;
            }

            // Case-insensitive language comparison
            if (strtolower($langAttribute) !== $requestedLang) {
                continue;
            }

            $results[] = [
                'server'   => $server,
                'language' => $langAttribute,
                'url'      => $href,
            ];
        }

        return $results;
    }
}
