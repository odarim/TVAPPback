<?php

namespace App\EventSubscriber;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\Device;
use App\Service\SessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DeviceLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SessionService $sessionService,
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

        // Admin accounts have unlimited devices — skip the limit check
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        // Use SessionService to respect user-level overrides (maxDevicesOverride)
        $maxDevices = $this->sessionService->getMaxDevices($user);
        $currentDevicesCount = count($user->getDevices());

        if ($currentDevicesCount >= $maxDevices) {
            $event->setResponse(new JsonResponse([
                'error' => sprintf(
                    'Device limit reached. Your account allows a maximum of %d device(s).',
                    $maxDevices
                )
            ], 403));
        }
    }
}
