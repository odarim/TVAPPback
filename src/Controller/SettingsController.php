<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * SettingsController
 *
 * Manages application-level settings persisted to var/settings.json.
 *
 * Routes:
 *   GET  /api/settings/public  — Public: returns only the fields safe for the client
 *   GET  /api/settings         — Admin: returns full settings object
 *   POST /api/settings         — Admin: updates settings
 */
#[Route('/api/settings')]
final class SettingsController extends AbstractController
{
    public function __construct(private readonly string $projectDir) {}

    // ─── helpers ────────────────────────────────────────────────────────────

    private function getSettingsFilePath(): string
    {
        return $this->projectDir . '/var/settings.json';
    }

    private function readSettings(): array
    {
        $path = $this->getSettingsFilePath();

        if (!file_exists($path)) {
            return ['anti_devtools_enabled' => true];
        }

        try {
            $content = file_get_contents($path);
            $data    = json_decode((string) $content, true);

            return is_array($data)
                ? array_merge(['anti_devtools_enabled' => true], $data)
                : ['anti_devtools_enabled' => true];
        } catch (\Throwable) {
            return ['anti_devtools_enabled' => true];
        }
    }

    private function saveSettings(array $settings): void
    {
        $path = $this->getSettingsFilePath();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT));
    }

    // ─── routes ─────────────────────────────────────────────────────────────

    /**
     * Public endpoint — no auth required.
     * Only exposes booleans that are safe for the client to read.
     */
    #[Route('/public', name: 'app_settings_public', methods: ['GET'])]
    public function getPublicSettings(): JsonResponse
    {
        $settings = $this->readSettings();

        return $this->json([
            'anti_devtools_enabled' => (bool) ($settings['anti_devtools_enabled'] ?? true),
        ]);
    }

    #[Route('', name: 'app_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->json($this->readSettings());
    }

    #[Route('', name: 'app_settings_update', methods: ['POST', 'PUT'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data     = json_decode($request->getContent(), true) ?? [];
        $settings = $this->readSettings();

        if (array_key_exists('anti_devtools_enabled', $data)) {
            $settings['anti_devtools_enabled'] = (bool) $data['anti_devtools_enabled'];
        }

        $this->saveSettings($settings);

        return $this->json(['success' => true, 'settings' => $settings]);
    }
}
