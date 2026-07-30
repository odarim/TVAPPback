<?php

namespace App\Service;

use App\Entity\ActiveSession;
use App\Entity\Device;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ActiveSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class SessionService
{
    /**
     * TTL in seconds: a session expires if no heartbeat is received within this duration.
     */
    private const SESSION_TTL = 120;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActiveSessionRepository $activeSessionRepository,
    ) {
    }

    /**
     * Returns the effective max devices for a user.
     * Uses the user-level override if set, otherwise falls back to the active package value.
     * Admin accounts (ROLE_ADMIN) have unlimited devices.
     */
    public function getMaxDevices(User $user): int
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return PHP_INT_MAX;
        }

        if ($user->getMaxDevicesOverride() !== null) {
            return $user->getMaxDevicesOverride();
        }

        $subscription = $this->getActiveSubscription($user);
        if (!$subscription) {
            return 1; // Default: 1 device if no subscription
        }

        return $subscription->getPackage()->getMaxDevices() ?? 1;
    }

    /**
     * Returns the effective max simultaneous connections for a user.
     * Uses the user-level override if set, otherwise falls back to the active package value.
     * Admin accounts (ROLE_ADMIN) have unlimited connections.
     */
    public function getMaxConnections(User $user): int
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return PHP_INT_MAX;
        }

        if ($user->getMaxConnectionsOverride() !== null) {
            return $user->getMaxConnectionsOverride();
        }

        $subscription = $this->getActiveSubscription($user);
        if (!$subscription) {
            return 1; // Default: 1 connection if no subscription
        }

        return $subscription->getPackage()->getMaxConnections() ?? 1;
    }

    /**
     * Starts a new streaming session for the given user and device.
     * Checks that the simultaneous connection limit has not been reached.
     *
     * @return ActiveSession The newly created session (with its token)
     * @throws \RuntimeException If the connection limit is reached or the device doesn't belong to the user
     */
    public function startSession(User $user, Device $device): ActiveSession
    {
        // Verify device belongs to user
        if ($device->getUser()?->getId() !== $user->getId()) {
            throw new \RuntimeException('This device does not belong to the authenticated user.', 403);
        }

        $maxConnections = $this->getMaxConnections($user);
        $activeCount = $this->activeSessionRepository->countActiveByUser($user, self::SESSION_TTL);

        if ($activeCount >= $maxConnections) {
            throw new \RuntimeException(
                sprintf(
                    'Simultaneous connection limit reached. Your plan allows %d simultaneous stream(s).',
                    $maxConnections
                ),
                403
            );
        }

        $session = new ActiveSession();
        $session->setUser($user);
        $session->setDevice($device);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Updates the heartbeat timestamp for a session identified by its token.
     * Returns the updated session, or null if the token is invalid or session is expired.
     */
    public function heartbeat(string $token): ?ActiveSession
    {
        $session = $this->activeSessionRepository->findByToken($token);

        if (!$session) {
            return null;
        }

        // If the session is expired, delete it and return null
        if ($session->isExpired(self::SESSION_TTL)) {
            $this->entityManager->remove($session);
            $this->entityManager->flush();

            return null;
        }

        $session->setLastHeartbeatAt(new \DateTime());
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Ends a session identified by its token.
     * Returns true if the session was found and deleted, false otherwise.
     */
    public function endSession(string $token, User $user): bool
    {
        $session = $this->activeSessionRepository->findByToken($token);

        if (!$session) {
            return false;
        }

        // Ensure the session belongs to the authenticated user
        if ($session->getUser()?->getId() !== $user->getId()) {
            return false;
        }

        $this->entityManager->remove($session);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Returns all active (non-expired) sessions for a user.
     *
     * @return ActiveSession[]
     */
    public function getActiveSessions(User $user): array
    {
        return $this->activeSessionRepository->findActiveByUser($user, self::SESSION_TTL);
    }

    /**
     * Deletes all expired sessions from the database.
     * Should be run periodically (e.g., via cron every 2-5 minutes).
     *
     * @return int Number of deleted sessions
     */
    public function cleanupExpiredSessions(): int
    {
        return $this->activeSessionRepository->deleteExpired(self::SESSION_TTL);
    }

    /**
     * Returns the active subscription for a user, or null if none found.
     */
    private function getActiveSubscription(User $user): ?Subscription
    {
        return $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $user,
            'isActive' => true,
        ]);
    }
}
