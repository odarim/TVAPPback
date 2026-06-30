<?php

namespace App\Controller;

use App\Entity\Device;
use App\Entity\User;
use App\Service\SessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/sessions', name: 'api_sessions_')]
class SessionController extends AbstractController
{
    public function __construct(
        private SessionService $sessionService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Start a streaming session for a device.
     *
     * Request body: { "deviceId": "<device_id_string>" }
     *
     * Returns the session token to use for heartbeats and session termination.
     */
    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $deviceIdStr = $data['deviceId'] ?? null;

        if (!$deviceIdStr) {
            return $this->json(['error' => 'deviceId is required.'], 400);
        }

        // Find the device by its deviceId string
        $device = $this->entityManager->getRepository(Device::class)->findOneBy([
            'deviceId' => $deviceIdStr,
            'user' => $user,
        ]);

        if (!$device) {
            return $this->json(['error' => 'Device not found or does not belong to your account.'], 404);
        }

        try {
            $session = $this->sessionService->startSession($user, $device);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        }

        return $this->json([
            'sessionId' => (string) $session->getId(),
            'token' => $session->getToken(),
            'startedAt' => $session->getStartedAt()?->format(\DateTime::ATOM),
            'message' => 'Session started. Send a heartbeat every 30-60 seconds to keep the stream alive.',
        ], 201);
    }

    /**
     * Heartbeat to keep a streaming session alive.
     *
     * Request body: { "token": "<session_token>" }
     */
    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->json(['error' => 'token is required.'], 400);
        }

        $session = $this->sessionService->heartbeat($token);

        if (!$session) {
            return $this->json(['error' => 'Session not found or expired. Please start a new session.'], 404);
        }

        return $this->json([
            'status' => 'alive',
            'lastHeartbeatAt' => $session->getLastHeartbeatAt()?->format(\DateTime::ATOM),
        ]);
    }

    /**
     * End a streaming session (clean disconnect).
     *
     * DELETE /api/sessions/{token}
     */
    #[Route('/{token}', name: 'end', methods: ['DELETE'])]
    public function end(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }

        $deleted = $this->sessionService->endSession($token, $user);

        if (!$deleted) {
            return $this->json(['error' => 'Session not found or does not belong to your account.'], 404);
        }

        return $this->json(['message' => 'Session ended successfully.']);
    }

    /**
     * List all active sessions for the current user.
     *
     * GET /api/sessions/active
     */
    #[Route('/active', name: 'active', methods: ['GET'])]
    public function active(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }

        $sessions = $this->sessionService->getActiveSessions($user);
        $maxConnections = $this->sessionService->getMaxConnections($user);
        $maxDevices = $this->sessionService->getMaxDevices($user);

        return $this->json([
            'activeSessions' => array_map(fn($s) => [
                'sessionId' => (string) $s->getId(),
                'token' => $s->getToken(),
                'device' => [
                    'id' => (string) $s->getDevice()?->getId(),
                    'deviceId' => $s->getDevice()?->getDeviceId(),
                    'deviceName' => $s->getDevice()?->getDeviceName(),
                ],
                'startedAt' => $s->getStartedAt()?->format(\DateTime::ATOM),
                'lastHeartbeatAt' => $s->getLastHeartbeatAt()?->format(\DateTime::ATOM),
            ], $sessions),
            'limits' => [
                'maxDevices' => $maxDevices,
                'maxConnections' => $maxConnections,
                'activeCount' => count($sessions),
                'availableSlots' => max(0, $maxConnections - count($sessions)),
            ],
        ]);
    }
}
