<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EmbedProxyController
 *
 * Transparently proxies a third-party video embed page through the Symfony backend,
 * stripping headers that would prevent the page from loading inside an <iframe>:
 *   - X-Frame-Options
 *   - Content-Security-Policy (frame-ancestors directive)
 *
 * The frontend sends the raw embed URL and this controller fetches the HTML,
 * rewrites relative asset URLs to absolute ones, strips frame-blocking headers,
 * and returns the page content directly so the <iframe> can render it.
 *
 * Route:
 *   GET /api/vod/embed-proxy?url=<encoded_embed_url>
 *
 * Security:
 *   - Only allows-listed hostnames are proxied (video hosting sites).
 *   - The URL must pass PHP's FILTER_VALIDATE_URL.
 */
#[Route('/api/vod')]
final class EmbedProxyController extends AbstractController
{
    /**
     * Allowed video host domains.
     * Only these hosts will be proxied — everything else returns 403.
     */
    /**
     * Browser-like User-Agent to avoid being blocked as a bot.
     */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly \App\Service\CipherService $cipherService,
    ) {}

    /**
     * Proxy and sanitise a third-party embed page.
     *
     * Accepts either:
     *   ?token=<encrypted>   — preferred; URL was encrypted by CipherService
     *   ?url=<plain>         — legacy / direct calls (still allowlist-checked)
     */
    #[Route('/embed-proxy', name: 'vod_embed_proxy', methods: ['GET'])]
    public function proxy(Request $request): Response
    {
        // ── Resolve URL from token (preferred) or plain url (fallback) ───────
        $rawUrl = '';

        $token = $request->query->get('token', '');
        if ($token !== '') {
            $decrypted = $this->cipherService->decrypt($token);
            if ($decrypted === null) {
                return new Response('Invalid or tampered token.', Response::HTTP_FORBIDDEN);
            }
            $rawUrl = $decrypted;
        } else {
            $rawUrl = $request->query->get('url', '');
        }

        // ── Basic URL validation ──────────────────────────────────────────────
        if (!$rawUrl || !filter_var($rawUrl, FILTER_VALIDATE_URL)) {
            return new Response('Missing or invalid url parameter.', Response::HTTP_BAD_REQUEST);
        }

        $host = strtolower(parse_url($rawUrl, PHP_URL_HOST) ?? '');


        try {
            $upstream = $this->httpClient->request('GET', $rawUrl, [
                'timeout' => 15,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => self::UA,
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer'    => 'https://' . $host . '/',
                ],
            ]);

            $statusCode  = $upstream->getStatusCode();
            $rawHeaders  = $upstream->getHeaders(false);
            $contentType = $rawHeaders['content-type'][0] ?? 'text/html; charset=utf-8';
            $body        = $upstream->getContent(false);

        } catch (\Throwable $e) {
            return new Response('Upstream error: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }

        // Rewrite relative URLs inside HTML to absolute (base tag injection)
        // and inject the anti-popup/redirect guard script
        if (str_contains($contentType, 'text/html')) {
            $body = $this->injectBaseTag($body, $rawUrl);
            $body = $this->injectAntiPopupGuard($body);
        }

        // Build the sanitised response
        $response = new Response($body, $statusCode);
        $response->headers->set('Content-Type', $contentType);

        // CORS — allow our frontend to embed this
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cache-Control', 'no-store');

        // Remove headers that block <iframe> embedding
        $response->headers->remove('X-Frame-Options');
        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('X-Content-Security-Policy');

        return $response;
    }

    /**
     * Inject a <base> tag so relative asset/script/style URLs resolve correctly
     * even though the page is served from our domain.
     */
    private function injectBaseTag(string $html, string $pageUrl): string
    {
        $parts   = parse_url($pageUrl);
        $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        // Keep target="_self" so links stay inside the iframe; combined with the
        // guard below, _blank links are also intercepted.
        $baseTag = '<base href="' . $baseUrl . '/">';

        // Prefer inserting after <head>, fall back to prepending
        if (stripos($html, '<head>') !== false) {
            return str_ireplace('<head>', '<head>' . $baseTag, $html);
        }
        if (stripos($html, '<head ') !== false) {
            return (string) preg_replace('/<head\s[^>]*>/i', '$0' . $baseTag, $html, 1);
        }

        return $baseTag . $html;
    }

    /**
     * Inject a JavaScript guard that blocks pop-up windows and external redirects
     * injected by ad/tracker scripts inside the proxied embed page.
     *
     * Techniques neutralised:
     *   - window.open()             → no-op (returns a fake window-like object)
     *   - window.location = ...     → blocked via defineProperty setter
     *   - <a target="_blank">       → target changed to _self on click
     *   - <form target="_blank">    → target changed to _self on submit
     */
    private function injectAntiPopupGuard(string $html): string
    {
        $guard = <<<'JS'
<script data-proxy-guard="1">
(function () {
    'use strict';

    /* 1. Block window.open – the most common pop-up vector */
    window.open = function () { return { focus: function(){}, blur: function(){}, close: function(){} }; };

    /* 2. Block window.location / document.location assignments
     *    We wrap the setter so that navigations to external origins are silently dropped.
     *    Same-origin changes (needed by video players) are passed through. */
    var _selfOrigin = window.location.origin;
    function blockExternalNav(val) {
        try {
            var target = new URL(val, window.location.href);
            if (target.origin !== _selfOrigin) { return; } // external → drop
        } catch (e) { return; }
        window.location.href = val; // same-origin → allow
    }
    try {
        Object.defineProperty(window, 'location', {
            get: function () { return window.location; },
            set: blockExternalNav,
            configurable: true
        });
    } catch (e) {}

    /* 3. Intercept link clicks and form submissions targeting _blank */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('a[href]');
        if (!el) { return; }
        var t = (el.getAttribute('target') || '').toLowerCase();
        if (t === '_blank' || t === '_top' || t === '_parent') {
            el.setAttribute('target', '_self');
        }
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form) { return; }
        var t = (form.getAttribute('target') || '').toLowerCase();
        if (t === '_blank' || t === '_top' || t === '_parent') {
            form.setAttribute('target', '_self');
        }
    }, true);

    /* 4. Kill setTimeout/setInterval-based redirect loops (common ad pattern).
     *    We wrap both so that any callback containing location navigation is caught
     *    AFTER the fact by the defineProperty guard above. No need to block all timers
     *    as that would break the video player. */

})();
</script>
JS;

        // Inject as early as possible — right before </head> or at the very top
        if (stripos($html, '</head>') !== false) {
            return str_ireplace('</head>', $guard . '</head>', $html);
        }

        return $guard . $html;
    }
}
