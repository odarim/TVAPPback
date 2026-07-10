<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * TorrentWorkerService
 *
 * Symfony service that acts as the HTTP client for the internal torrent worker.
 *
 * All communication between Symfony and the worker goes through this class.
 * It is the ONLY place in the Symfony codebase that knows about the worker's
 * URL and secret — everything else uses this service via dependency injection.
 *
 * Authentication: every request sends the shared X-Worker-Secret header.
 * The worker rejects requests that do not match its configured WORKER_SECRET.
 */
class TorrentWorkerService
{
    /**
     * Default timeout in seconds for requests to the worker.
     * Increase if torrents with very slow metadata fetching are expected.
     */
    private const REQUEST_TIMEOUT = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private readonly string              $workerUrl,
        private readonly string              $workerSecret,
    ) {
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Instructs the worker to start a new torrent session.
     *
     * @param string $source  Magnet URI, torrent URL, or info-hash
     * @return array{sessionId: string, status: string}
     *
     * @throws \RuntimeException If the worker is unreachable or returns an error
     */
    public function startStream(string $source): array
    {
        $this->logger->info('[TorrentWorkerService] Starting stream', ['source' => $source]);

        $data = $this->post('/session/start', ['source' => $source]);

        if (empty($data['sessionId'])) {
            throw new \RuntimeException('Worker returned an unexpected response from /session/start');
        }

        return $data;
    }

    /**
     * Fetches the current status of a torrent session from the worker.
     *
     * @param string $sessionId  The UUID returned by startStream()
     * @return array{status: string, progress: int, speed: int, peers: int, streamUrl: string|null, error: string|null}
     *
     * @throws \RuntimeException If the worker is unreachable or the session is not found
     */
    public function getStatus(string $sessionId): array
    {
        $this->logger->debug('[TorrentWorkerService] Fetching status', ['sessionId' => $sessionId]);

        return $this->get("/session/{$sessionId}/status");
    }

    /**
     * Instructs the worker to stop a torrent session and clean up its resources.
     *
     * @param string $sessionId  The UUID returned by startStream()
     * @return void
     *
     * @throws \RuntimeException If the worker is unreachable
     */
    public function stopStream(string $sessionId): void
    {
        $this->logger->info('[TorrentWorkerService] Stopping stream', ['sessionId' => $sessionId]);

        try {
            $this->delete("/session/{$sessionId}");
        } catch (\RuntimeException $e) {
            // A 404 from the worker means the session was already cleaned up — that is fine.
            if (str_contains($e->getMessage(), '404')) {
                $this->logger->info(
                    '[TorrentWorkerService] Session already gone on worker (404)',
                    ['sessionId' => $sessionId]
                );
                return;
            }
            throw $e;
        }
    }

    /**
     * Returns a raw stream from the worker for a given session.
     * Used by the Symfony proxy endpoint to pipe bytes directly to the browser.
     *
     * @param string $sessionId  The worker session UUID
     * @param string $rangeHeader  Value of the client's Range HTTP header (may be empty)
     * @return \Symfony\Contracts\HttpClient\ResponseInterface
     *
     * @throws \RuntimeException If the worker is unreachable
     */
    public function openStream(string $sessionId, string $rangeHeader = ''): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $headers = $this->buildHeaders();
        if ($rangeHeader) {
            $headers['Range'] = $rangeHeader;
        }

        try {
            return $this->httpClient->request('GET', $this->workerUrl . "/session/{$sessionId}/stream", [
                'headers' => $headers,
                'timeout' => 0, // Streaming — no timeout
                'buffer'  => false,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[TorrentWorkerService] Stream open failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Worker is unreachable: ' . $e->getMessage(), 503, $e);
        }
    }

    /**
     * Pings the worker's /health endpoint.
     * Returns true if the worker is healthy, false otherwise.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->workerUrl . '/health', [
                'timeout' => 3.0,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Private HTTP helpers ─────────────────────────────────────────────────

    /** @return array<string, string> */
    private function buildHeaders(): array
    {
        return [
            'Content-Type'    => 'application/json',
            'X-Worker-Secret' => $this->workerSecret,
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->workerUrl . $path, [
                'headers' => $this->buildHeaders(),
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->toArray(false);

            if ($statusCode >= 400) {
                throw new \RuntimeException(
                    sprintf('Worker returned %d: %s', $statusCode, $body['error'] ?? 'Unknown error')
                );
            }

            return $body;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[TorrentWorkerService] GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Worker is unreachable: ' . $e->getMessage(), 503, $e);
        }
    }

    /**
     * @param string $path
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->workerUrl . $path, [
                'headers' => $this->buildHeaders(),
                'json'    => $payload,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->toArray(false);

            if ($statusCode >= 400) {
                throw new \RuntimeException(
                    sprintf('Worker returned %d: %s', $statusCode, $body['error'] ?? 'Unknown error')
                );
            }

            return $body;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[TorrentWorkerService] POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Worker is unreachable: ' . $e->getMessage(), 503, $e);
        }
    }

    /**
     * @param string $path
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->workerUrl . $path, [
                'headers' => $this->buildHeaders(),
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->toArray(false);

            if ($statusCode >= 400) {
                throw new \RuntimeException(
                    sprintf('Worker returned %d: %s', $statusCode, $body['error'] ?? 'Unknown error')
                );
            }

            return $body;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[TorrentWorkerService] DELETE failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Worker is unreachable: ' . $e->getMessage(), 503, $e);
        }
    }
}
