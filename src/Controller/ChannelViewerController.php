<?php

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\ChannelViewerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Live "watching now" viewer tracking.
 * Open to guests (anyone watching should count); deviceId dedupes a single
 * device so re-opening a channel doesn't double-count.
 */
#[Route('/api/watch', name: 'api_watch_')]
class ChannelViewerController extends AbstractController
{
    public function __construct(
        private ChannelViewerService $channelViewerService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Register that a device started watching a channel.
     *
     * Request body: { "channelId": "<channel_id>", "deviceId": "<optional device string>" }
     */
    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $channelId = $data['channelId'] ?? null;
        $deviceId = isset($data['deviceId']) ? (string) $data['deviceId'] : null;

        if (!$channelId) {
            return $this->json(['error' => 'channelId is required.'], 400);
        }

        $channel = $this->entityManager->getRepository(Channel::class)->find($channelId);
        if (!$channel) {
            return $this->json(['error' => 'Channel not found.'], 404);
        }

        $viewer = $this->channelViewerService->start($channel, $user, $deviceId);

        return $this->json([
            'token' => $viewer->getToken(),
            'channelId' => (string) $channel->getId(),
            'startedAt' => $viewer->getStartedAt()?->format(\DateTime::ATOM),
            'expiresIn' => ChannelViewerService::VIEWER_TTL ?? 120,
            'message' => 'Watching now. Send a heartbeat every 30-60 seconds to keep the count live.',
        ], 201);
    }

    /**
     * Live "watching now" counts for a batch of channels.
     *
     * Query: /api/watch/counts?channels=id1,id2,id3
     * Returns a map of channelId => live viewer count.
     * Lets the catalog poll a single lightweight request instead of a full list.
     */
    #[Route('/counts', name: 'counts', methods: ['GET'])]
    public function counts(Request $request): JsonResponse
    {
        $raw = $request->query->get('channels', '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));

        if ($ids === []) {
            return $this->json([]);
        }

        $channels = $this->entityManager->getRepository(Channel::class)->findBy(['id' => $ids]);
        if ($channels === []) {
            return $this->json([]);
        }

        $counts = $this->channelViewerService->countForChannels($channels);

        // Ensure every requested channel is present in the response (0 when idle).
        foreach ($channels as $channel) {
            $counts[(string) $channel->getId()] ??= 0;
        }

        return $this->json($counts);
    }

    /**
     * Heartbeat to keep a viewer row alive.
     *
     * Request body: { "token": "<viewer_token>" }
     */
    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->json(['error' => 'token is required.'], 400);
        }

        $viewer = $this->channelViewerService->heartbeat($token);

        if (!$viewer) {
            return $this->json(['error' => 'Viewer session not found or expired.'], 404);
        }

        return $this->json([
            'status' => 'alive',
            'lastHeartbeatAt' => $viewer->getLastHeartbeatAt()?->format(\DateTime::ATOM),
        ]);
    }

    /**
     * End a viewer session (clean disconnect).
     *
     * DELETE /api/watch/{token}
     */
    #[Route('/{token}', name: 'stop', methods: ['DELETE'])]
    public function stop(string $token): JsonResponse
    {
        $deleted = $this->channelViewerService->stop($token);

        if (!$deleted) {
            return $this->json(['error' => 'Viewer session not found.'], 404);
        }

        return $this->json(['message' => 'Watching stopped.']);
    }
}