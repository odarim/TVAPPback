<?php

namespace App\Repository;

use App\Entity\ActiveSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveSession>
 */
class ActiveSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveSession::class);
    }

    /**
     * Returns all non-expired sessions for a given user.
     *
     * @param int $ttlSeconds Session TTL in seconds (default: 120)
     * @return ActiveSession[]
     */
    public function findActiveByUser(User $user, int $ttlSeconds = 120): array
    {
        $expiredBefore = new \DateTime("-{$ttlSeconds} seconds");

        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.lastHeartbeatAt >= :expiredBefore')
            ->setParameter('user', $user)
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts active (non-expired) sessions for a given user.
     *
     * @param int $ttlSeconds Session TTL in seconds (default: 120)
     */
    public function countActiveByUser(User $user, int $ttlSeconds = 120): int
    {
        $expiredBefore = new \DateTime("-{$ttlSeconds} seconds");

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.lastHeartbeatAt >= :expiredBefore')
            ->setParameter('user', $user)
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Finds a session by its token. Returns null if not found.
     */
    public function findByToken(string $token): ?ActiveSession
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Deletes all sessions that have not received a heartbeat within $ttlSeconds.
     *
     * @return int Number of deleted sessions
     */
    public function deleteExpired(int $ttlSeconds = 120): int
    {
        $expiredBefore = new \DateTime("-{$ttlSeconds} seconds");

        return $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.lastHeartbeatAt < :expiredBefore')
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute();
    }
}
