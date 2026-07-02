<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Full transparent HLS proxy for LiveWatch streams.
 * 
 * Flow:
 * 1. VideoPlayer calls /api/livewatch-token/{id}        → gets the resolved proxy_url
 * 2. VideoPlayer calls /api/livewatch-hls?url=<encoded> → gets the m3u8 manifest with rewritten URLs
 * 3. VideoJS calls /api/livewatch-hls?url=<encoded>     → sub-playlists and segments, all proxied
 */
#[Route('/api')]
class LivewatchProxyController extends AbstractController
{
    private const LW_HEADERS = [
        'User-Agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Referer'     => 'https://livewatch.top/',
        'Origin'      => 'https://livewatch.top',
        'Accept'      => '*/*',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Step 1: Resolve the embed URL → get the proxy_url token from LiveWatch API.
     */
    #[Route('/livewatch-token/{id}', name: 'livewatch_token_proxy', methods: ['GET'])]
    public function getToken(string $id): JsonResponse
    {
        try {
            $response = $this->httpClient->request('GET', "https://livewatch.top/api/stream/{$id}", [
                'timeout' => 10,
                'headers' => self::LW_HEADERS,
            ]);

            $data = $response->toArray();

            if (isset($data['proxy_url'])) {
                $absoluteUrl = str_starts_with($data['proxy_url'], 'http')
                    ? $data['proxy_url']
                    : 'https://livewatch.top' . $data['proxy_url'];

                // Return our own proxy URL instead of the direct livewatch URL
                $data['proxy_url'] = '/api/livewatch-hls?url=' . urlencode($absoluteUrl);
            }

            return $this->json($data);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to fetch stream token: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Step 2 & 3: Transparent HLS proxy — fetches any m3u8 or .ts segment from LiveWatch,
     * rewrites URLs in playlists to also go through this proxy, and streams segments directly.
     */
    #[Route('/livewatch-hls', name: 'livewatch_hls_proxy', methods: ['GET'])]
    public function proxyHls(Request $request): Response
    {
        $url = $request->query->get('url');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new Response('Invalid or missing url parameter', 400);
        }

        // Security: only allow proxying livewatch.top and their CDN domains
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !str_contains($host, 'livewatch.top') && !str_ends_with($host, '.org')) {
            return new Response('Forbidden host', 403);
        }

        try {
            $upstreamResponse = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => self::LW_HEADERS,
            ]);

            $content     = $upstreamResponse->getContent();
            $statusCode  = $upstreamResponse->getStatusCode();
            $contentType = $upstreamResponse->getHeaders(false)['content-type'][0] ?? 'application/octet-stream';

            // If it's an HLS playlist, rewrite all URLs inside it
            if (str_contains($contentType, 'mpegurl') || str_contains($contentType, 'x-mpegURL') || str_ends_with(strtok($url, '?'), '.m3u8')) {
                $content     = $this->rewritePlaylist($content, $url);
                $contentType = 'application/vnd.apple.mpegurl';
            }

            $response = new Response($content, $statusCode);
            $response->headers->set('Content-Type', $contentType);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Cache-Control', 'no-cache, no-store');

            return $response;
        } catch (\Throwable $e) {
            return new Response('Proxy error: ' . $e->getMessage(), 502);
        }
    }

    /**
     * Rewrites all URLs in an HLS playlist so they go through this proxy.
     */
    private function rewritePlaylist(string $content, string $baseUrl): string
    {
        $baseUrlParts = parse_url($baseUrl);
        $baseDir      = $baseUrlParts['scheme'] . '://' . $baseUrlParts['host'] . rtrim(dirname($baseUrlParts['path'] ?? '/'), '/') . '/';

        $lines   = explode("\n", $content);
        $rewritten = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Rewrite URI= attributes inside tags (e.g. #EXT-X-KEY:URI="...")
            if (str_starts_with($line, '#') && str_contains($line, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseDir) {
                    return 'URI="' . $this->toProxyUrl($m[1], $baseDir) . '"';
                }, $line);
                $rewritten[] = $line;
                continue;
            }

            // Rewrite actual segment/playlist lines (non-comment, non-empty)
            if ($line !== '' && !str_starts_with($line, '#')) {
                $line = $this->toProxyUrl($line, $baseDir);
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    /**
     * Converts a segment URL (relative or absolute) into a proxied /api/livewatch-hls?url=... URL.
     */
    private function toProxyUrl(string $url, string $baseDir): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $absoluteUrl = $url;
        } elseif (str_starts_with($url, '/')) {
            $parts       = parse_url($baseDir);
            $absoluteUrl = $parts['scheme'] . '://' . $parts['host'] . $url;
        } else {
            $absoluteUrl = $baseDir . $url;
        }

        return '/api/livewatch-hls?url=' . urlencode($absoluteUrl);
    }
}
