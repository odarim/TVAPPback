<?php

namespace App\Service;

use App\Entity\Channel;
use App\Entity\ChannelViewer;
use App\Entity\User;
use App\Repository\ChannelViewerRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChannelViewerService
{
    /**
     * TTL in seconds: a viewer row expires if no heartbeat is received within
     * this duration. Rows that expire are pruned so the count == live viewers.
     */
    public const VIEWER_TTL = 120;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChannelViewerRepository $channelViewerRepository,
    ) {
    }

    /**
     * Registers a device as watching the given channel, or refreshes an existing
     * viewer row for the same device + channel (so re-opening a channel doesn't
     * double-count). The user is recorded when authenticated.
     */
    public function start(Channel $channel, ?User $user, ?string $deviceId): ChannelViewer
    {
        $existing = $this->channelViewerRepository->findByChannelAndDevice($channel, $deviceId);

        if ($existing) {
            $existing->setLastHeartbeatAt(new \DateTime());
            $this->entityManager->flush();

            return $existing;
        }

        $viewer = new ChannelViewer();
        $viewer->setChannel($channel);
        $viewer->setUser($user);
        $viewer->setDeviceId($deviceId);

        $this->entityManager->persist($viewer);
        $this->entityManager->flush();

        return $viewer;
    }

    /**
     * Refreshes the heartbeat for a viewer identified by its token.
     * Returns the viewer, or null if the token is invalid/expired.
     */
    public function heartbeat(string $token): ?ChannelViewer
    {
        $viewer = $this->channelViewerRepository->findByToken($token);

        if (!$viewer) {
            return null;
        }

        if ($this->isExpired($viewer)) {
            $this->entityManager->remove($viewer);
            $this->entityManager->flush();

            return null;
        }

        $viewer->setLastHeartbeatAt(new \DateTime());
        $this->entityManager->flush();

        return $viewer;
    }

    /**
     * Ends a viewer session identified by its token.
     */
    public function stop(string $token): bool
    {
        $viewer = $this->channelViewerRepository->findByToken($token);

        if (!$viewer) {
            return false;
        }

        $this->entityManager->remove($viewer);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Live "watching now" count for a single channel.
     */
    public function countForChannel(Channel $channel): int
    {
        return $this->channelViewerRepository->countActiveByChannel($channel, self::VIEWER_TTL);
    }

    /**
     * Live "watching now" counts for a batch of channels, keyed by channel id.
     *
     * @param Channel[] $channels
     * @return array<string, int>
     */
    public function countForChannels(array $channels): array
    {
        return $this->channelViewerRepository->countActiveByChannels($channels, self::VIEWER_TTL);
    }

    /**
     * Deletes expired viewer rows from the database.
     * Should be run periodically (e.g., via cron every 2-5 minutes).
     *
     * @return int Number of deleted rows
     */
    public function cleanupExpired(): int
    {
        return $this->channelViewerRepository->deleteExpired(self::VIEWER_TTL);
    }

    private function isExpired(ChannelViewer $viewer): bool
    {
        $last = $viewer->getLastHeartbeatAt();
        if (!$last) {
            return true;
        }

        return $last < new \DateTime("-" . self::VIEWER_TTL . " seconds");
    }
}