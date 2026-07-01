<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Public health/readiness endpoint.
 *
 * Lives outside the ^/api firewall, so it requires no authentication and no
 * JWT machinery. Reports overall app status plus a database connectivity check,
 * returning 200 when healthy and 503 when a critical dependency is down — so a
 * platform health check (e.g. Render's healthCheckPath) won't route traffic to
 * an instance that can't reach its database.
 */
class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'app_health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database connectivity — a fast round-trip that fails fast if the DB
        // (Render or Supabase, depending on DB_PROVIDER) is unreachable.
        try {
            $connection->executeQuery('SELECT 1');
            $checks['database'] = 'up';
        } catch (\Throwable $e) {
            $checks['database'] = 'down';
            $healthy = false;
        }

        return $this->json(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
