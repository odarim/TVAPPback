<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/devices')]
class DeviceManagerController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $devices = array_map(fn($d) => [
            'id' => $d->getId(),
            'name' => method_exists($d, 'getName') ? $d->getName() : null,
            'adultContentUnlocked' => $d->isAdultContentUnlocked(),
            'adultContentUnlockedAt' => $d->getAdultContentUnlockedAt()?->format(DATE_ATOM),
        ], $user->getDevices()->toArray());

        return new JsonResponse(['devices' => $devices]);
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
}