<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\User;
use App\Repository\ActiveSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/adult-lock')]
class AdultLockController extends AbstractController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    public function __construct(
        private EntityManagerInterface $em,
        private ActiveSessionRepository $activeSessionRepository,
        private CacheInterface $cache,
    ) {
    }

    private function resolveCurrentDevice(Request $request, User $user): ?Device
    {
        $token = str_replace('Bearer ', '', $request->headers->get('Authorization', ''));
        $session = $this->activeSessionRepository->findOneBy(['token' => $token, 'user' => $user]);

        return $session?->getDevice();
    }

    private function getFailureCacheKey(User $user, Request $request): string
    {
        $ip = $request->getClientIp() ?? '0.0.0.0';

        return 'adult_lock_fail_' . $user->getId() . '_' . $ip;
    }

    private function getFailureCount(string $cacheKey): int
    {
        return (int) $this->cache->get($cacheKey, function (ItemInterface $item): int {
            $item->expiresAfter(self::LOCKOUT_SECONDS);

            return 0;
        });
    }

    private function incrementFailureCount(string $cacheKey): void
    {
        $count = $this->getFailureCount($cacheKey);
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($count): int {
            $item->expiresAfter(self::LOCKOUT_SECONDS);

            return $count + 1;
        });
    }

    private function clearFailureCount(string $cacheKey): void
    {
        $this->cache->delete($cacheKey);
    }

    private function readSettings(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/settings.json';

        if (!file_exists($path)) {
            return [];
        }

        try {
            $content = file_get_contents($path);
            $data = json_decode((string) $content, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    #[Route('', methods: ['GET'])]
    public function getConfig(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $device = $this->resolveCurrentDevice($request, $user);

        $deviceUnlocked = $device?->isAdultContentUnlocked() ?? false;

        // Auto-relock if timeout has elapsed
        if ($deviceUnlocked && $device !== null) {
            $settings = $this->readSettings();
            $timeoutMinutes = (int) ($settings['adult_lock_timeout_minutes'] ?? 30);

            if ($timeoutMinutes > 0 && $device->getAdultContentUnlockedAt() !== null) {
                $elapsed = time() - $device->getAdultContentUnlockedAt()->getTimestamp();
                if ($elapsed > ($timeoutMinutes * 60)) {
                    $device->setAdultContentUnlocked(false);
                    $this->em->flush();
                    $deviceUnlocked = false;
                }
            }
        }

        return new JsonResponse([
            'hasPassword' => $user->getAdultLockPasswordHash() !== null,
            'salt' => $user->getAdultLockSalt(),
            'deviceUnlocked' => $deviceUnlocked,
            'deviceId' => $device?->getId(),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function setPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $passwordHash = $data['passwordHash'] ?? null;
        $salt = $data['salt'] ?? null;

        if (!is_string($passwordHash) || !is_string($salt) || $passwordHash === '' || $salt === '') {
            return new JsonResponse(['error' => 'passwordHash and salt are required.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        // If a password already exists, verify the current one before allowing change
        if ($user->getAdultLockPasswordHash() !== null) {
            $currentPasswordHash = $data['currentPasswordHash'] ?? null;

            if (!is_string($currentPasswordHash) || !hash_equals($user->getAdultLockPasswordHash(), $currentPasswordHash)) {
                return new JsonResponse(['error' => 'Current adult lock password is incorrect.'], Response::HTTP_FORBIDDEN);
            }
        }

        $user->setAdultLockPasswordHash($passwordHash);
        $user->setAdultLockSalt($salt);
        $user->setAdultLockUpdatedAt(new \DateTimeImmutable());

        $device = $this->resolveCurrentDevice($request, $user);
        $device?->setAdultContentUnlocked(true);

        $this->em->flush();

        return new JsonResponse(['hasPassword' => true, 'salt' => $salt, 'deviceUnlocked' => true]);
    }

    #[Route('/verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $passwordHash = $data['passwordHash'] ?? null;

        /** @var User $user */
        $user = $this->getUser();
        $storedHash = $user->getAdultLockPasswordHash();

        if (!is_string($passwordHash) || $storedHash === null) {
            return new JsonResponse(['valid' => false]);
        }

        // Rate limiting
        $failureKey = $this->getFailureCacheKey($user, $request);
        $failureCount = $this->getFailureCount($failureKey);

        if ($failureCount >= self::MAX_ATTEMPTS) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Too many failed attempts. Please try again later.',
                'locked' => true,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $valid = hash_equals($storedHash, $passwordHash);

        if ($valid) {
            $this->clearFailureCount($failureKey);

            $device = $this->resolveCurrentDevice($request, $user);
            $device?->setAdultContentUnlocked(true);
            $this->em->flush();
        } else {
            $this->incrementFailureCount($failureKey);
        }

        return new JsonResponse(['valid' => $valid]);
    }

    #[Route('/lock', methods: ['POST'])]
    public function lockCurrentDevice(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $device = $this->resolveCurrentDevice($request, $user);
        $device?->setAdultContentUnlocked(false);
        $this->em->flush();

        return new JsonResponse(['deviceUnlocked' => false]);
    }

    #[Route('', methods: ['DELETE'])]
    public function clear(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->setAdultLockPasswordHash(null);
        $user->setAdultLockSalt(null);
        $user->setAdultLockUpdatedAt(null);

        foreach ($user->getDevices() as $device) {
            $device->setAdultContentUnlocked(false);
        }

        $this->em->flush();

        return new JsonResponse(['hasPassword' => false, 'salt' => null]);
    }
}
