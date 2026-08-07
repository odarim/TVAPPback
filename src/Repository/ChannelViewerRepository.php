<?php

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ChannelViewer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelViewer>
 */
class ChannelViewerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelViewer::class);
    }

    /**
     * Returns the live viewer count for a single channel within the given TTL.
     */
    public function countActiveByChannel(Channel $channel, int $ttlSeconds): int
    {
        $activeBefore = new \DateTime("-{$ttlSeconds} seconds");

        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.channel = :channel')
            ->andWhere('v.lastHeartbeatAt >= :activeBefore')
            ->setParameter('channel', $channel)
            ->setParameter('activeBefore', $activeBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns a map of channelId => live viewer count for the given channels.
     *
     * @param Channel[] $channels
     * @return array<string, int>
     */
    public function countActiveByChannels(array $channels, int $ttlSeconds): array
    {
        if ($channels === []) {
            return [];
        }

        $active = new \DateTime("-{$ttlSeconds} seconds");
        $ids = array_map(static fn (Channel $c) => $c->getId(), $channels);

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.channel) AS channel_id, COUNT(v.id) AS cnt')
            ->andWhere('v.channel IN (:channels)')
            ->andWhere('v.lastHeartbeatAt >= :activeBefore')
            ->setParameter('channels', $ids)
            ->setParameter('activeBefore', $active)
            ->groupBy('v.channel')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['channel_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function findByToken(string $token): ?ChannelViewer
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findByChannelAndDevice(Channel $channel, ?string $deviceId): ?ChannelViewer
    {
        if ($deviceId === null) {
            return null;
        }

        return $this->findOneBy([
            'channel' => $channel,
            'deviceId' => $deviceId,
        ]);
    }

    /**
     * Deletes viewer rows that haven't heartbeated within $ttlSeconds.
     *
     * @return int Number of deleted rows
     */
    public function deleteExpired(int $ttlSeconds = 90): int
    {
        $expiredBefore = new \DateTime("-{$ttlSeconds} seconds");

        return $this->createQueryBuilder('v')
            ->delete()
            ->andWhere('v.lastHeartbeatAt < :expiredBefore')
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute();
    }
}