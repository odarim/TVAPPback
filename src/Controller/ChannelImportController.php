<?php

namespace App\Controller;

use App\Service\ChannelImportService;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin')]
class ChannelImportController extends AbstractController
{
    public function __construct(
        private ChannelImportService $importService,
        private CategoryRepository $categoryRepository
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
}
