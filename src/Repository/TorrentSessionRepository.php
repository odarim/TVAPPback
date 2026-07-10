<?php

namespace App\Repository;

use App\Entity\TorrentSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * TorrentSessionRepository
 *
 * @extends ServiceEntityRepository<TorrentSession>
 */
class TorrentSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TorrentSession::class);
    }

    /**
     * Finds a TorrentSession by its stream token.
     * Used by Symfony endpoints to validate that a client-provided token
     * corresponds to a real, active session before proxying stream data.
     *
     * @param string $token The 64-character hex stream token
     * @return TorrentSession|null
     */
    public function findByStreamToken(string $token): ?TorrentSession
    {
        return $this->findOneBy(['streamToken' => $token]);
    }

    /**
     * Finds a TorrentSession by the worker's internal session UUID.
     *
     * @param string $sessionId The UUID assigned by the worker
     * @return TorrentSession|null
     */
    public function findBySessionId(string $sessionId): ?TorrentSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    /**
     * Returns all non-terminal sessions belonging to a user.
     * Useful for showing "currently streaming" sessions in the UI.
     *
     * @param User $user
     * @return TorrentSession[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.user = :user')
            ->andWhere('ts.status NOT IN (:terminalStatuses)')
            ->setParameter('user', $user)
            ->setParameter('terminalStatuses', [
                TorrentSession::STATUS_STOPPED,
                TorrentSession::STATUS_ERROR,
            ])
            ->orderBy('ts.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes all TorrentSession records that have been inactive for more than
     * $ttlSeconds.  Should be called periodically (e.g., via a Symfony command
     * or scheduled task) to prevent the table from growing unbounded.
     *
     * @param int $ttlSeconds Number of seconds of inactivity before expiry
     * @return int Number of deleted records
     */
    public function deleteExpiredSessions(int $ttlSeconds = 3600): int
    {
        $cutoff = new \DateTimeImmutable("-{$ttlSeconds} seconds");

        return $this->createQueryBuilder('ts')
            ->delete()
            ->where('ts.lastActivity < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
