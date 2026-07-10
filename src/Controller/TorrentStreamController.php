<?php

namespace App\Controller;

use App\Entity\TorrentSession;
use App\Entity\User;
use App\Repository\TorrentSessionRepository;
use App\Service\TorrentWorkerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * TorrentStreamController
 *
 * Symfony API layer for torrent streaming.  The React frontend talks ONLY to
 * these endpoints — the worker is never exposed to the browser.
 *
 * Route prefix: /api/stream
 *
 * Security model:
 *  - All endpoints require a valid JWT (enforced by Symfony's firewall)
 *  - The client receives a streamToken (not the worker sessionId) on start
 *  - Every subsequent request uses that streamToken, which is validated
 *    against the database and the authenticated user before hitting the worker
 */
#[Route('/api/stream', name: 'api_torrent_stream_')]
class TorrentStreamController extends AbstractController
{
    public function __construct(
        private readonly TorrentWorkerService      $workerService,
        private readonly TorrentSessionRepository  $torrentSessionRepository,
        private readonly EntityManagerInterface    $entityManager,
        private readonly LoggerInterface           $logger,
    ) {
    }

    // ─── POST /api/stream/start ───────────────────────────────────────────────

    /**
     * Starts a new torrent streaming session.
     *
     * Request body:
     *   { "source": "<magnet URI | torrent URL | info-hash>" }
     *
     * Response (201):
     *   {
     *     "streamToken": "<64-char hex token>",
     *     "status":      "STARTING"
     *   }
     */
    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $body   = json_decode($request->getContent(), true);
        $source = trim($body['source'] ?? '');

        if ($source === '') {
            return $this->json(['error' => '`source` is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Ask the worker to create the session
        try {
            $workerResponse = $this->workerService->startStream($source);
        } catch (\RuntimeException $e) {
            $this->logger->error('[TorrentStreamController] Worker start failed: ' . $e->getMessage());
            return $this->json(['error' => 'Streaming service unavailable. ' . $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Persist the session record in our database
        $session = new TorrentSession();
        $session->setUser($user);
        $session->setSessionId($workerResponse['sessionId']);
        $session->setStatus(TorrentSession::STATUS_STARTING);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->logger->info('[TorrentStreamController] Session created', [
            'userId'      => (string) $user->getId(),
            'sessionId'   => $session->getSessionId(),
            'streamToken' => substr($session->getStreamToken(), 0, 8) . '...',
        ]);

        return $this->json([
            'streamToken' => $session->getStreamToken(),
            'status'      => $session->getStatus(),
        ], Response::HTTP_CREATED);
    }

    // ─── GET /api/stream/{token}/status ──────────────────────────────────────

    /**
     * Returns the current status of a torrent streaming session.
     *
     * Response (200):
     *   {
     *     "status":   "BUFFERING",
     *     "progress": 50,
     *     "speed":    2000000,
     *     "peers":    10
     *   }
     */
    #[Route('/{token}/status', name: 'status', methods: ['GET'])]
    public function status(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->resolveSession($token, $user);
        if ($session instanceof JsonResponse) {
            return $session; // Error response
        }

        // Fetch live status from the worker
        try {
            $workerStatus = $this->workerService->getStatus($session->getSessionId());
        } catch (\RuntimeException $e) {
            $this->logger->error('[TorrentStreamController] Worker status fetch failed: ' . $e->getMessage());
            return $this->json(['error' => 'Could not retrieve stream status.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Mirror status changes back into our database for audit / UI consistency
        if ($workerStatus['status'] !== $session->getStatus()) {
            $session->setStatus($workerStatus['status']);
        }
        $session->touchLastActivity();
        $this->entityManager->flush();

        return $this->json([
            'status'   => $workerStatus['status'],
            'progress' => $workerStatus['progress'] ?? 0,
            'speed'    => $workerStatus['speed']    ?? 0,
            'peers'    => $workerStatus['peers']    ?? 0,
            'error'    => $workerStatus['error']    ?? null,
        ]);
    }

    // ─── GET /api/stream/{token}/proxy ────────────────────────────────────────

    /**
     * Proxies the video byte stream from the worker to the browser.
     * Supports Range requests (HTTP 206 Partial Content) for seeking.
     *
     * The worker URL is never exposed to the browser — this endpoint acts as
     * a transparent gateway, forwarding the client's Range header to the worker
     * and streaming the response bytes back.
     */
    #[Route('/{token}/proxy', name: 'proxy', methods: ['GET'])]
    public function proxy(string $token, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->resolveSession($token, $user);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        // Only allow streaming if the session is in a streamable state
        if (\in_array($session->getStatus(), [
            TorrentSession::STATUS_STARTING,
            TorrentSession::STATUS_FETCHING_METADATA,
            TorrentSession::STATUS_STOPPED,
            TorrentSession::STATUS_ERROR,
        ], true)) {
            return $this->json(
                ['error' => 'Stream is not ready yet. Status: ' . $session->getStatus()],
                Response::HTTP_CONFLICT
            );
        }

        $rangeHeader = $request->headers->get('Range', '');

        try {
            $workerResponse = $this->workerService->openStream($session->getSessionId(), $rangeHeader);
        } catch (\RuntimeException $e) {
            $this->logger->error('[TorrentStreamController] Proxy open failed: ' . $e->getMessage());
            return $this->json(['error' => 'Streaming service unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $workerStatusCode = $workerResponse->getStatusCode();

        // Build a StreamedResponse that pipes worker bytes directly to the browser.
        // toStream() returns a PHP stream resource — we read it in 8 KB chunks.
        $streamedResponse = new StreamedResponse(static function () use ($workerResponse) {
            $stream = $workerResponse->toStream(false);
            while (!feof($stream)) {
                echo fread($stream, 8192);
                flush();
            }
        }, $workerStatusCode);

        // Forward relevant headers from worker response to browser
        $headersToForward = [
            'Content-Type',
            'Content-Length',
            'Content-Range',
            'Accept-Ranges',
            'Cache-Control',
        ];

        foreach ($headersToForward as $headerName) {
            $headerValue = $workerResponse->getHeaders(false)[$headerName][0] ?? null;
            if ($headerValue !== null) {
                $streamedResponse->headers->set($headerName, $headerValue);
            }
        }

        // Update last activity
        $session->touchLastActivity();
        $this->entityManager->flush();

        return $streamedResponse;
    }

    // ─── DELETE /api/stream/{token} ───────────────────────────────────────────

    /**
     * Stops the torrent session and cleans up resources on the worker.
     *
     * Response (200):
     *   { "message": "Session stopped." }
     */
    #[Route('/{token}', name: 'stop', methods: ['DELETE'])]
    public function stop(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->resolveSession($token, $user);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        // Tell the worker to stop and clean up (best-effort)
        try {
            $this->workerService->stopStream($session->getSessionId());
        } catch (\RuntimeException $e) {
            // Log but do not fail — we still want to mark the DB record as stopped
            $this->logger->warning('[TorrentStreamController] Worker stop failed (non-fatal): ' . $e->getMessage());
        }

        $session->setStatus(TorrentSession::STATUS_STOPPED);
        $session->touchLastActivity();
        $this->entityManager->flush();

        return $this->json(['message' => 'Session stopped.']);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Looks up a TorrentSession by stream token and validates ownership.
     *
     * @param string $token  The client-provided stream token
     * @param User   $user   The authenticated user
     * @return TorrentSession|JsonResponse  Returns the session, or a JsonResponse on failure
     */
    private function resolveSession(string $token, User $user): TorrentSession|JsonResponse
    {
        $session = $this->torrentSessionRepository->findByStreamToken($token);

        if (!$session) {
            return $this->json(['error' => 'Stream session not found.'], Response::HTTP_NOT_FOUND);
        }

        // Ownership check — prevent users from accessing each other's streams
        if ($session->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        return $session;
    }
}
