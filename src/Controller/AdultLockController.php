<?php

namespace App\Controller;

use App\Entity\Device;
use App\Entity\User;
use App\Repository\ActiveSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/adult-lock')]
class AdultLockController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActiveSessionRepository $activeSessionRepository,
    ) {
    }

    /**
     * Resolves the Device tied to the current request's auth token.
     * Adjust the token source (bearer token vs X-Device-Id header) to match
     * however sessions are actually tracked in your auth layer.
     */
    private function resolveCurrentDevice(Request $request, User $user): ?Device
    {
        $token = str_replace('Bearer ', '', $request->headers->get('Authorization', ''));
        $session = $this->activeSessionRepository->findOneBy(['token' => $token, 'user' => $user]);

        return $session?->getDevice();
    }

    #[Route('', methods: ['GET'])]
    public function getConfig(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $device = $this->resolveCurrentDevice($request, $user);

        return new JsonResponse([
            'hasPassword' => $user->getAdultLockPasswordHash() !== null,
            'salt' => $user->getAdultLockSalt(),
            // This device's own unlock state — NOT global to the account.
            'deviceUnlocked' => $device?->isAdultContentUnlocked() ?? false,
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
        $user->setAdultLockPasswordHash($passwordHash);
        $user->setAdultLockSalt($salt);
        $user->setAdultLockUpdatedAt(new \DateTimeImmutable());

        // Setting a new password only unlocks the device that set it —
        // every other device stays locked until unlocked with the new password.
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

        $valid = hash_equals($storedHash, $passwordHash);

        if ($valid) {
            // Correct password unlocks THIS device only.
            $device = $this->resolveCurrentDevice($request, $user);
            $device?->setAdultContentUnlocked(true);
            $this->em->flush();
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

        // Removing the password re-locks every device account-wide —
        // a stale unlocked flag with no password would be a bypass.
        foreach ($user->getDevices() as $device) {
            $device->setAdultContentUnlocked(false);
        }

        $this->em->flush();

        return new JsonResponse(['hasPassword' => false, 'salt' => null]);
    }
}