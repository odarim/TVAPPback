<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChannelHealthController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {}

    #[Route('/api/check-health', name: 'api_check_health', methods: ['POST'])]
    public function checkHealth(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $urls = $data['urls'] ?? [];

        if (!is_array($urls) || empty($urls)) {
            return new JsonResponse(['error' => 'No URLs provided in the request body.'], 400);
        }

        $results = [];

        foreach ($urls as $url) {
            $targetUrl = $url;
            $errorMsg = null;

            // 1. If it's a LiveWatch embed URL, resolve the actual stream first
            if (str_contains($url, 'livewatch.top/embed/')) {
                $parts = explode('/embed/', $url);
                $idWithQuery = $parts[1] ?? '';
                $id = explode('?', $idWithQuery)[0] ?? null;

                if ($id) {
                    try {
                        $res = $this->httpClient->request('GET', "https://livewatch.top/api/stream/{$id}", [
                            'timeout' => 5,
                            'headers' => [
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                                'Referer' => 'https://livewatch.top/'
                            ]
                        ]);
                        $data = $res->toArray(false);
                        if (isset($data['proxy_url'])) {
                            $targetUrl = str_starts_with($data['proxy_url'], 'http') 
                                ? $data['proxy_url'] 
                                : 'https://livewatch.top' . $data['proxy_url'];
                        } else {
                            // API returned success but no stream url
                            $targetUrl = null; 
                        }
                    } catch (\Throwable $e) {
                        $targetUrl = null;
                        $errorMsg = 'Failed to resolve LiveWatch stream token';
                    }
                }
            }

            // 2. Perform the actual health check on the target stream URL
            $httpCode = 0;
            $status = 'unknown';

            if ($targetUrl) {
                try {
                    $response = $this->httpClient->request('HEAD', $targetUrl, [
                        'timeout' => 5,
                        'max_redirects' => 3,
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                        ]
                    ]);
                    $httpCode = $response->getStatusCode();
                } catch (\Throwable $e) {
                    $errorMsg = $errorMsg ?: $e->getMessage();
                }
            }

            // 3. Determine status
            if ($errorMsg) {
                $status = 'error';
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                $status = 'working';
            } elseif ($httpCode === 403) {
                $status = 'blocked'; // Geo-blocked or token restricted
            } elseif ($httpCode === 404 || $httpCode === 0) {
                $status = 'dead';
            } else {
                $status = 'error';
            }

            $results[] = [
                'url' => $url,
                'status' => $status,
                'http_code' => $httpCode,
                'error_msg' => $errorMsg
            ];
        }

        return new JsonResponse($results, 200);
    }
}
