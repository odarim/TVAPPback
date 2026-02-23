<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use App\Entity\Channel;
use App\Entity\Category;
use App\Entity\User;

final class GlobalSearchFilter extends AbstractFilter
{
    protected function filterProperty(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, Operation $operation = null, array $context = []): void
    {
        if ($property !== 'q' || empty($value)) {
            return;
        }

        $parameterName = $queryNameGenerator->generateParameterName('q');
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $orX = $queryBuilder->expr()->orX();
        $hasFields = false;

        // Use str_ends_with or similar to be robust against leading backslashes
        if (str_ends_with($resourceClass, 'Channel')) {
            $orX->add($queryBuilder->expr()->like("$rootAlias.name", ":$parameterName"));
            
            // Join streams to search in URL
            $streamAlias = $queryNameGenerator->generateJoinAlias('streams');
            $queryBuilder->leftJoin("$rootAlias.streams", $streamAlias);
            $orX->add($queryBuilder->expr()->like("$streamAlias.url", ":$parameterName"));
            $hasFields = true;
        } elseif (str_ends_with($resourceClass, 'Category')) {
            $orX->add($queryBuilder->expr()->like("$rootAlias.name", ":$parameterName"));
            $orX->add($queryBuilder->expr()->like("$rootAlias.slug", ":$parameterName"));
            $orX->add($queryBuilder->expr()->like("$rootAlias.description", ":$parameterName"));
            $hasFields = true;
        } elseif (str_ends_with($resourceClass, 'User')) {
            $orX->add($queryBuilder->expr()->like("$rootAlias.email", ":$parameterName"));
            $orX->add($queryBuilder->expr()->like("$rootAlias.fullName", ":$parameterName"));
            $hasFields = true;
        }

        if (!$hasFields) {
            return;
        }

        $queryBuilder
            ->andWhere($orX)
            ->setParameter($parameterName, '%' . $value . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'q' => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'openapi' => [
                    'description' => 'Search across multiple fields (OR logic)',
                ],
            ],
        ];
    }
}
