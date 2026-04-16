<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Channel;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

class ChannelCollectionExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private Security $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (Channel::class !== $resourceClass) {
            return;
        }

        // Admins can see everything
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $user = $this->security->getUser();

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if (!$user) {
            // Anonymous users see nothing
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        // Normal users only see channels belonging to their active packages
        $queryBuilder->innerJoin(sprintf('%s.packages', $rootAlias), 'p')
            ->innerJoin('p.subscriptions', 's')
            ->andWhere('s.user = :currentUser')
            ->andWhere('s.isActive = true')
            ->andWhere('(s.endDate IS NULL OR s.endDate > :now)')
            ->setParameter('currentUser', $user)
            ->setParameter('now', new \DateTime());
    }
}
