<?php

namespace App\Controller;

use App\Entity\Device;
use App\Entity\User;
use App\Service\SessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/devices')]
class DeviceManagerController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SessionService $sessionService,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $currentDeviceId = $request->headers->get('X-Device-Id', '');
        $currentToken = $request->headers->get('X-Device-Token', '');

        $devices = array_map(fn($d) => [
            'id' => (string) $d->getId(),
            'deviceId' => $d->getDeviceId(),
            'name' => $d->getDeviceName(),
            'deviceType' => $d->getDeviceType(),
            'lastActiveAt' => $d->getLastActiveAt()?->format(DATE_ATOM),
            'createdAt' => $d->getCreatedAt()?->format(DATE_ATOM),
            'adultContentUnlocked' => $d->isAdultContentUnlocked(),
            'isCurrent' => $d->getDeviceId() === $currentDeviceId,
            'token' => (string) $d->getToken() === $currentToken ? $d->getToken() : null,
        ], $user->getDevices()->toArray());

        $maxDevices = $this->sessionService->getMaxDevices($user);

        return new JsonResponse([
            'devices' => $devices,
            'limits' => [
                'maxDevices' => $maxDevices,
                'unlimited' => $maxDevices === PHP_INT_MAX,
                'used' => count($devices),
                'remaining' => $maxDevices === PHP_INT_MAX ? -1 : max(0, $maxDevices - count($devices)),
            ],
        ]);
    }

    // Registers or updates the current device.
    // Called on app startup to link a browser/device to the user's account.
    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $deviceId = $data['deviceId'] ?? null;
        $deviceName = $data['deviceName'] ?? null;
        $deviceType = $data['deviceType'] ?? null;

        if (!$deviceId) {
            return $this->json(['error' => 'deviceId is required.'], 400);
        }

        // Check if device already exists for this user
        $existingDevice = null;
        foreach ($user->getDevices() as $device) {
            if ($device->getDeviceId() === $deviceId) {
                $existingDevice = $device;
                break;
            }
        }

        if ($existingDevice) {
            // Update last active timestamp and name/type if changed
            $existingDevice->setLastActiveAt(new \DateTime());
            if ($deviceName) $existingDevice->setDeviceName($deviceName);
            if ($deviceType) $existingDevice->setDeviceType($deviceType);
            $this->em->flush();
            return $this->json([
                'registered' => true,
                'deviceId' => (string) $existingDevice->getId(),
                'newDevice' => false,
                'token' => $existingDevice->getToken(),
            ]);
        }

        // New device — enforce the device limit
        $maxDevices = $this->sessionService->getMaxDevices($user);
        if (count($user->getDevices()) >= $maxDevices) {
            return $this->json([
                'error' => sprintf('Device limit reached. Your account allows a maximum of %d device(s).', $maxDevices),
            ], 403);
        }

        $device = new Device();
        $device->setUser($user);
        $device->setDeviceId($deviceId);
        $device->setDeviceName($deviceName);
        $device->setDeviceType($deviceType);
        $device->setLastActiveAt(new \DateTime());
        $device->setToken(bin2hex(random_bytes(32)));

        $this->em->persist($device);
        $this->em->flush();

        return $this->json([
            'registered' => true,
            'deviceId' => (string) $device->getId(),
            'newDevice' => true,
            'token' => $device->getToken(),
        ], 201);
    }

    // Lets the user lock a DIFFERENT device remotely (e.g. lock device 2
    // from device 1) without needing the password again — they're already
    // authenticated as the account owner.
    #[Route('/{id}/adult-lock', methods: ['DELETE'])]
    public function lockDevice(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        foreach ($user->getDevices() as $device) {
            if ((string) $device->getId() === $id) {
                $device->setAdultContentUnlocked(false);
                $this->em->flush();
                return new JsonResponse(['adultContentUnlocked' => false]);
            }
        }

        return new JsonResponse(['error' => 'Device not found.'], 404);
    }

    // Remove a device from the account. Requires account password verification
    // for security (like Netflix).
    #[Route('/{id}/remove', methods: ['DELETE'])]
    public function removeDevice(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        // Verify the account password before allowing device removal
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Incorrect password.'], 403);
        }

        foreach ($user->getDevices() as $device) {
            if ((string) $device->getId() === $id) {
                // Remove any active sessions for this device
                foreach ($user->getActiveSessions() as $session) {
                    if ($session->getDevice()?->getId() === $device->getId()) {
                        $this->em->remove($session);
                    }
                }
                $this->em->remove($device);
                $this->em->flush();
                return new JsonResponse(['message' => 'Device removed successfully.']);
            }
        }

        return new JsonResponse(['error' => 'Device not found.'], 404);
    }
}
