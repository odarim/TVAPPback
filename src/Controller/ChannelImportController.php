<?php

namespace App\Controller;

use App\Service\ChannelImportService;
use App\Service\LivewatchSyncService;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
class ChannelImportController extends AbstractController
{
    public function __construct(
        private ChannelImportService $importService,
        private CategoryRepository $categoryRepository,
        private LivewatchSyncService $livewatchSyncService,
        private \App\Service\ChannelMergeService $channelMergeService,
    ) {
    }

    #[Route('/channel/import', name: 'admin_channel_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $channels = $data['channels'] ?? null;
        $categoryId = $data['categoryId'] ?? null;

        if (!$channels || !is_array($channels)) {
            return $this->json(['error' => 'JSON must contain a "channels" array'], 400);
        }

        $category = null;
        if ($categoryId) {
            $category = $this->categoryRepository->find($categoryId);
        }

        $stats = $this->importService->import($channels, $category);

        return $this->json([
            'message' => 'Import processed',
            'stats' => $stats
        ]);
    }

    /**
     * Fetch the raw data from LiveWatch API.
     */
    #[Route('/channel/livewatch-fetch', name: 'admin_channel_livewatch_fetch', methods: ['GET'])]
    public function livewatchFetch(): JsonResponse
    {
        try {
            $data = $this->livewatchSyncService->fetchRawData();
            return $this->json($data);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Fetch failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Process a chunk of livewatch channels.
     */
    #[Route('/channel/sync-livewatch-chunk', name: 'admin_channel_sync_livewatch_chunk', methods: ['POST'])]
    public function syncLivewatchChunk(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $categories = $data['categories'] ?? [];
            $channels = $data['channels'] ?? [];

            if (!is_array($categories) || !is_array($channels)) {
                return $this->json(['error' => 'Invalid JSON payload'], 400);
            }

            $stats = $this->livewatchSyncService->processChunk($categories, $channels);

            return $this->json([
                'message' => 'Chunk processed',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Sync chunk failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Merge duplicate channels by slug.
     */
    #[Route('/channel/merge-duplicates', name: 'admin_channel_merge_duplicates', methods: ['POST'])]
    public function mergeDuplicates(): JsonResponse
    {
        try {
            $stats = $this->channelMergeService->mergeDuplicates();
            return $this->json([
                'message' => 'Duplicates merged successfully',
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Merge failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Backfill/fix the language column on every channel, derived from its country.
     * By default only fills in channels missing a language; pass
     * { "overwrite_all": true } in the request body to recompute all of them.
     */
    #[Route('/channel/backfill-language', name: 'admin_channel_backfill_language', methods: ['POST'])]
    public function backfillLanguage(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $overwriteAll = (bool) ($data['overwrite_all'] ?? false);

            $stats = $this->livewatchSyncService->backfillLanguages($overwriteAll);

            return $this->json([
                'message' => 'Language backfill complete',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Backfill failed: ' . $e->getMessage()], 500);
        }
    }
}

