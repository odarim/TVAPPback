<?php

namespace App\Controller;

use App\Service\ChannelLogoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
class ChannelLogoController extends AbstractController
{
    public function __construct(
        private readonly ChannelLogoService $logoService,
    ) {
    }

    /**
     * Batch-fetches logos for all channels missing one (or all, with
     * overwrite=true). Sources: iptv-org then Wikimedia Commons.
     */
    #[Route('/channel/backfill-logos', name: 'admin_channel_backfill_logos', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function backfill(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $overwrite = (bool) ($data['overwrite'] ?? false);

            $stats = $this->logoService->backfill($overwrite);

            return $this->json([
                'message' => 'Logo backfill complete',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Backfill failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Returns the list of channels missing a logo, so the frontend can process
     * them in chunks (mirrors the LiveWatch sync flow).
     */
    #[Route('/channel/missing-logos', name: 'admin_channel_missing_logos', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function missingLogos(): JsonResponse
    {
        try {
            return $this->json(['channels' => $this->logoService->channelsWithoutLogos()]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to list channels: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Resolves logos for a batch of channels ({id, name, country}[]). Called
     * repeatedly by the frontend until all pending channels are processed.
     */
    #[Route('/channel/backfill-logos-chunk', name: 'admin_channel_backfill_logos_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function backfillChunk(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $channels = $data['channels'] ?? [];

            if (!is_array($channels)) {
                return $this->json(['error' => 'channels must be an array'], 400);
            }

            $stats = $this->logoService->processChunk($channels);

            return $this->json([
                'message' => 'Chunk processed',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Chunk failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Resolves a logo for a single channel name, so the admin form can
     * auto-fill the Logo URL while adding/editing a channel.
     */
    #[Route('/channel/fetch-logo', name: 'admin_channel_fetch_logo', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function fetch(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                return $this->json(['error' => 'name is required'], 400);
            }

            $country = isset($data['country']) && is_string($data['country']) ? $data['country'] : null;
            $logo = $this->logoService->resolve($name, $country);

            return $this->json(['logo' => $logo]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Logo fetch failed: ' . $e->getMessage()], 500);
        }
    }
}
