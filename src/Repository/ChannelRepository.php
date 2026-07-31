<?php

namespace App\Repository;

use App\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /**
     * Returns only the IDs of active channels matching the given filters.
     * Scalar query on purpose so bulk assignment over thousands of channels
     * stays far below PHP's execution time limit.
     *
     * @param string[]|null $sourceTypes normalized lowercase stream types (null = all)
     */
    public function findIdsByFilters(?array $sourceTypes, ?string $categoryName, string $search): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id')
            ->distinct()
            ->where('c.isActive = true');

        if ($sourceTypes !== null) {
            $qb->join('c.streams', 's')
                ->andWhere('LOWER(s.type) IN (:sourceTypes)')
                ->setParameter('sourceTypes', array_map('strtolower', $sourceTypes));
        }

        if ($categoryName !== null && $categoryName !== '') {
            $qb->join('c.category', 'cat')
                ->andWhere('LOWER(cat.name) = LOWER(:categoryName)')
                ->setParameter('categoryName', $categoryName);
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        $rows = $qb->getQuery()->getScalarResult();

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            $rows
        );
    }
}
