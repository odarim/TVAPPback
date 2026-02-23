<?php

namespace App\EventSubscriber;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\Device;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DeviceLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['checkDeviceLimit', EventPriorities::PRE_WRITE],
        ];
    }

    public function checkDeviceLimit(ViewEvent $event): void
    {
        $device = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if (!$device instanceof Device || $method !== 'POST') {
            return;
        }

        $user = $device->getUser();
        if (!$user) {
            return;
        }

        // Get active subscription
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $user,
            'isActive' => true
        ]);

        if (!$subscription) {
            $event->setResponse(new JsonResponse(['error' => 'User has no active subscription.'], 403));
            return;
        }

        $package = $subscription->getPackage();
        $maxDevices = $package->getMaxDevices();

        $currentDevicesCount = count($user->getDevices());

        if ($currentDevicesCount >= $maxDevices) {
            $event->setResponse(new JsonResponse([
                'error' => sprintf('Device limit reached. Your package allows a maximum of %d devices.', $maxDevices)
            ], 403));
        }
    }
}
