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
    private const DEFAULT_SETTINGS = [
        'anti_devtools_enabled'      => true,
        'adult_lock_timeout_minutes' => 30,
        'vod_sources' => [
            'enabled'          => ['alpha' => true, 'beta' => true, 'charlie' => true],
            'disabled_servers' => [],
        ],
    ];

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
            return self::DEFAULT_SETTINGS;
        }

        try {
            $content = file_get_contents($path);
            $data    = json_decode((string) $content, true);

            if (!is_array($data)) {
                return self::DEFAULT_SETTINGS;
            }

            return array_merge(self::DEFAULT_SETTINGS, $data);
        } catch (\Throwable) {
            return self::DEFAULT_SETTINGS;
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
     * Only exposes booleans and non-sensitive config safe for the client.
     */
    #[Route('/public', name: 'app_settings_public', methods: ['GET'])]
    public function getPublicSettings(): JsonResponse
    {
        $settings = $this->readSettings();

        return $this->json([
            'anti_devtools_enabled'      => (bool) ($settings['anti_devtools_enabled'] ?? true),
            'adult_lock_timeout_minutes' => (int) ($settings['adult_lock_timeout_minutes'] ?? 30),
            'vod_sources' => [
                'enabled'          => $settings['vod_sources']['enabled'] ?? self::DEFAULT_SETTINGS['vod_sources']['enabled'],
                'disabled_servers' => $settings['vod_sources']['disabled_servers'] ?? [],
            ],
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

        if (array_key_exists('adult_lock_timeout_minutes', $data)) {
            $settings['adult_lock_timeout_minutes'] = max(0, (int) $data['adult_lock_timeout_minutes']);
        }

        if (array_key_exists('vod_sources', $data)) {
            $vodInput = $data['vod_sources'];

            if (isset($vodInput['enabled']) && is_array($vodInput['enabled'])) {
                $settings['vod_sources']['enabled'] = array_merge(
                    $settings['vod_sources']['enabled'] ?? self::DEFAULT_SETTINGS['vod_sources']['enabled'],
                    $vodInput['enabled']
                );
            }

            if (isset($vodInput['disabled_servers']) && is_array($vodInput['disabled_servers'])) {
                $settings['vod_sources']['disabled_servers'] = array_values(array_unique(array_map('strtolower', $vodInput['disabled_servers'])));
            }
        }

        $this->saveSettings($settings);

        return $this->json(['success' => true, 'settings' => $settings]);
    }
}
