<?php

namespace App\EventSubscriber;

use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DeviceTokenSubscriber implements EventSubscriberInterface
{
    private const PUBLIC_PATHS = [
        '/api/login_check',
        '/api/login',
        '/api/signup',
        '/api/token/refresh',
        '/api/logout',
        '/api/health',
        '/api/docs',
        '/api',
        '/api/payment/notification',
        '/api/livewatch-token',
        '/api/livewatch-hls',
        '/api/vod/embed-proxy',
        '/api/settings/public',
        '/api/devices/register',
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        // Only check /api routes
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Skip public paths
        foreach (self::PUBLIC_PATHS as $path) {
            if ($request->getPathInfo() === $path || str_starts_with($request->getPathInfo(), $path . '/')) {
                return;
            }
        }

        // Skip OPTIONS preflight
        if ($request->isMethod('OPTIONS')) {
            return;
        }

        $deviceToken = $request->headers->get('X-Device-Token', '');
        if (!$deviceToken) {
            return;
        }

        $device = $this->em->getRepository(Device::class)->findOneBy(['token' => $deviceToken]);
        if ($device) {
            $request->attributes->set('_device', $device);
            return;
        }

        // Device token provided but device not found — the device was removed
        // from another browser. Reject immediately so the frontend can force
        // logout instead of continuing with a stale session.
        $event->setResponse(new JsonResponse([
            'error' => 'device_removed',
            'message' => 'This device has been removed from your account.',
        ], 401));
    }
}
