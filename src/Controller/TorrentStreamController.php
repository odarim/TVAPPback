<?php
//
//namespace App\Controller;
//
//use Psr\Log\LoggerInterface;
//use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
//use Symfony\Component\HttpFoundation\Request;
//use Symfony\Component\HttpFoundation\JsonResponse;
//use Symfony\Component\HttpFoundation\Response;
//use Symfony\Component\Routing\Annotation\Route;
//
///**
// * TorrentStreamController
// *
// * Handles torrent-related API endpoints.
// * Streaming is now browser-side via WebTorrent (no Node worker needed).
// * This controller only proxies the torrent catalogue search to APIBay.
// *
// * Route prefix: /api/stream
// */
//#[Route('/api/stream', name: 'api_torrent_stream_')]
//class TorrentStreamController extends AbstractController
//{
//    public function __construct(
//        private readonly LoggerInterface $logger,
//    ) {
//    }
//
//    // ─── GET /api/stream/search ────────────────────────────────────────────────
//
//    /**
//     * Proxies a torrent search query to APIBay so the browser never needs
//     * to call APIBay directly (avoids CORS issues).
//     *
//     * Query param: q — the search term (e.g. "Avengers" or "Stranger Things S01E01")
//     */
//    #[Route('/search', name: 'search', methods: ['GET'])]
//    public function searchTorrents(Request $request): JsonResponse
//    {
//        $query = trim($request->query->get('q', ''));
//
//        if ($query === '') {
//            return $this->json(['error' => 'Query parameter `q` is required.'], Response::HTTP_BAD_REQUEST);
//        }
//
//        $apibayUrl = sprintf('https://apibay.org/q.php?q=%s', urlencode($query));
//
//        $context = stream_context_create([
//            'http' => [
//                'method'  => 'GET',
//                'timeout' => 10,
//                'header'  => "User-Agent: Mozilla/5.0\r\n",
//            ],
//            'ssl' => [
//                'verify_peer'      => false,
//                'verify_peer_name' => false,
//            ],
//        ]);
//
//        $raw = @file_get_contents($apibayUrl, false, $context);
//
//        if ($raw === false) {
//            $this->logger->error('[TorrentSearch] Failed to reach APIBay');
//            return $this->json(['error' => 'Failed to fetch torrent results from upstream.'], Response::HTTP_BAD_GATEWAY);
//        }
//
//        $data = json_decode($raw, true);
//
//        // APIBay returns [{id:"0", ...}] when no results found
//        if (!is_array($data) || count($data) === 0 || ($data[0]['id'] ?? '') === '0') {
//            return $this->json([]);
//        }
//
//        $results = array_map(static function (array $t): array {
//            $infoHash = strtolower($t['info_hash'] ?? '');
//            return [
//                'id'        => $t['id'] ?? null,
//                'title'     => $t['name'] ?? '',
//                'infoHash'  => $infoHash,
//                'size'      => (int) ($t['size'] ?? 0),
//                'seeders'   => (int) ($t['seeders'] ?? 0),
//                'leechers'  => (int) ($t['leechers'] ?? 0),
//                'magnetUrl' => sprintf(
//                    'magnet:?xt=urn:btih:%s&dn=%s',
//                    $infoHash,
//                    urlencode($t['name'] ?? '')
//                ),
//            ];
//        }, $data);
//
//        // Sort by seeders descending
//        usort($results, static fn(array $a, array $b) => $b['seeders'] <=> $a['seeders']);
//
//        return $this->json($results);
//    }
//}


namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * TorrentStreamController
 *
 * Handles torrent-related API endpoints.
 * Streaming is now browser-side via WebTorrent (no Node worker needed).
 * This controller only proxies the torrent catalogue search to APIBay
 * (with mirror fallback — the official domain gets rate-limited /
 * blocked from datacenter IPs like Render's fairly often).
 *
 * Route prefix: /api/stream
 */
#[Route('/api/stream', name: 'api_torrent_stream_')]
class TorrentStreamController extends AbstractController
{
    // Try these in order — APIBay mirrors rotate / get blocked frequently.
    private const APIBAY_HOSTS = [
        'https://apibay.org/q.php?q=%s',
        'https://apibay.org/precompiled/data_top100_recent.json', // example placeholder for future use
        'https://thepiratebay10.org/apibay/q.php?q=%s',
    ];

    public function __construct(
        private readonly LoggerInterface     $logger,
        private readonly HttpClientInterface $httpClient,
    )
    {
    }

    // ─── GET /api/stream/search ────────────────────────────────────────────────

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchTorrents(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            return $this->json(['error' => 'Query parameter `q` is required.'], Response::HTTP_BAD_REQUEST);
        }

        $data = null;
        $lastError = null;

        foreach (self::APIBAY_HOSTS as $hostTemplate) {
            if (!str_contains($hostTemplate, '%s')) {
                continue; // skip placeholder entries without a query slot
            }

            $url = sprintf($hostTemplate, urlencode($query));

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 8,
                    'max_duration' => 10,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (compatible; NexusTv/1.0)',
                    ],
                ]);

                // getContent() throws if status >= 400 or transport fails
                $raw = $response->getContent();
                $data = json_decode($raw, true);

                if (is_array($data)) {
                    break; // success — stop trying other mirrors
                }
            } catch (TransportExceptionInterface $e) {
                // This is the key upgrade: we now know WHY it failed
                // (DNS, connection refused, TLS, timeout, etc.)
                $lastError = $e->getMessage();
                $this->logger->error('[TorrentSearch] Transport failure reaching upstream', [
                    'url' => $url,
                    'error' => $lastError,
                ]);
                continue;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('[TorrentSearch] Unexpected failure reaching upstream', [
                    'url' => $url,
                    'error' => $lastError,
                ]);
                continue;
            }
        }

        if ($data === null) {
            $this->logger->error('[TorrentSearch] All upstream sources failed', ['last_error' => $lastError]);
            // Graceful degrade: empty result set instead of a hard 502.
            // The frontend already renders an empty-state UI for zero results,
            // so this keeps the UX intact instead of surfacing a network error.
            return $this->json([]);
        }

        // APIBay returns [{id:"0", ...}] when no results found
        if (count($data) === 0 || ($data[0]['id'] ?? '') === '0') {
            return $this->json([]);
        }

        $results = array_map(static function (array $t): array {
            $infoHash = strtolower($t['info_hash'] ?? '');
            return [
                'id' => $t['id'] ?? null,
                'title' => $t['name'] ?? '',
                'infoHash' => $infoHash,
                'size' => (int)($t['size'] ?? 0),
                'seeders' => (int)($t['seeders'] ?? 0),
                'leechers' => (int)($t['leechers'] ?? 0),
                'magnetUrl' => sprintf(
                    'magnet:?xt=urn:btih:%s&dn=%s',
                    $infoHash,
                    urlencode($t['name'] ?? '')
                ),
            ];
        }, $data);

        usort($results, static fn(array $a, array $b) => $b['seeders'] <=> $a['seeders']);

        return $this->json($results);
    }
}