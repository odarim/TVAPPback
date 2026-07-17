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
    private const ALLOWED_HOSTS = [
        'dood.to',
        'dood.yt',
        'dooood.com',
        'ds2play.com',
        'streamtape.com',
        'streamtape.net',
        'voe.sx',
        'supervideo.cc',
        'supervideo.tv',
        'streamvid.net',
        'vidplay.online',
        'filemoon.sx',
        'filemoon.to',
        'upstream.to',
    ];

    /**
     * Browser-like User-Agent to avoid being blocked as a bot.
     */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Proxy and sanitise a third-party embed page.
     */
    #[Route('/embed-proxy', name: 'vod_embed_proxy', methods: ['GET'])]
    public function proxy(Request $request): Response
    {
        $rawUrl = $request->query->get('url', '');

        // --- Basic URL validation ---
        if (!$rawUrl || !filter_var($rawUrl, FILTER_VALIDATE_URL)) {
            return new Response('Missing or invalid url parameter.', Response::HTTP_BAD_REQUEST);
        }

        $host = strtolower(parse_url($rawUrl, PHP_URL_HOST) ?? '');

        // --- Allowlist check ---
        $allowed = array_filter(
            self::ALLOWED_HOSTS,
            static fn (string $h): bool => $host === $h || str_ends_with($host, '.' . $h)
        );

        if (empty($allowed)) {
            return new Response('Host not allowed: ' . $host, Response::HTTP_FORBIDDEN);
        }

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
        if (str_contains($contentType, 'text/html')) {
            $body = $this->injectBaseTag($body, $rawUrl);
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

        $baseTag = '<base href="' . $baseUrl . '/" target="_blank">';

        // Prefer inserting after <head>, fall back to prepending
        if (stripos($html, '<head>') !== false) {
            return str_ireplace('<head>', '<head>' . $baseTag, $html);
        }
        if (stripos($html, '<head ') !== false) {
            return (string) preg_replace('/<head\s[^>]*>/i', '$0' . $baseTag, $html, 1);
        }

        return $baseTag . $html;
    }
}
