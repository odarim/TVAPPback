<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * TorrentStreamController
 *
 * Handles torrent-related API endpoints.
 * Streaming is now browser-side via WebTorrent (no Node worker needed).
 * This controller only proxies the torrent catalogue search to APIBay.
 *
 * Route prefix: /api/stream
 */
#[Route('/api/stream', name: 'api_torrent_stream_')]
class TorrentStreamController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─── GET /api/stream/search ────────────────────────────────────────────────

    /**
     * Proxies a torrent search query to APIBay so the browser never needs
     * to call APIBay directly (avoids CORS issues).
     *
     * Query param: q — the search term (e.g. "Avengers" or "Stranger Things S01E01")
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchTorrents(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            return $this->json(['error' => 'Query parameter `q` is required.'], Response::HTTP_BAD_REQUEST);
        }

        $apibayUrl = sprintf('https://apibay.org/q.php?q=%s', urlencode($query));

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'header'  => "User-Agent: Mozilla/5.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $raw = @file_get_contents($apibayUrl, false, $context);

        if ($raw === false) {
            $this->logger->error('[TorrentSearch] Failed to reach APIBay');
            return $this->json(['error' => 'Failed to fetch torrent results from upstream.'], Response::HTTP_BAD_GATEWAY);
        }

        $data = json_decode($raw, true);

        // APIBay returns [{id:"0", ...}] when no results found
        if (!is_array($data) || count($data) === 0 || ($data[0]['id'] ?? '') === '0') {
            return $this->json([]);
        }

        $results = array_map(static function (array $t): array {
            $infoHash = strtolower($t['info_hash'] ?? '');
            return [
                'id'        => $t['id'] ?? null,
                'title'     => $t['name'] ?? '',
                'infoHash'  => $infoHash,
                'size'      => (int) ($t['size'] ?? 0),
                'seeders'   => (int) ($t['seeders'] ?? 0),
                'leechers'  => (int) ($t['leechers'] ?? 0),
                'magnetUrl' => sprintf(
                    'magnet:?xt=urn:btih:%s&dn=%s',
                    $infoHash,
                    urlencode($t['name'] ?? '')
                ),
            ];
        }, $data);

        // Sort by seeders descending
        usort($results, static fn(array $a, array $b) => $b['seeders'] <=> $a['seeders']);

        return $this->json($results);
    }
}
