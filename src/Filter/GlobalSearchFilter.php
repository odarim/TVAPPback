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

        // Wraps a field in LOWER() so LIKE matching is case-insensitive
        // regardless of the DB's column/table collation.
        $ci = fn (string $field) => "LOWER($field)";

        // Use str_ends_with or similar to be robust against leading backslashes
        if (str_ends_with($resourceClass, 'Channel')) {
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.name"), ":$parameterName"));

            // Join streams to search in URL
            $streamAlias = $queryNameGenerator->generateJoinAlias('streams');
            $queryBuilder->leftJoin("$rootAlias.streams", $streamAlias);
            $orX->add($queryBuilder->expr()->like($ci("$streamAlias.url"), ":$parameterName"));
            $hasFields = true;
        } elseif (str_ends_with($resourceClass, 'Category')) {
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.name"), ":$parameterName"));
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.slug"), ":$parameterName"));
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.description"), ":$parameterName"));
            $hasFields = true;
        } elseif (str_ends_with($resourceClass, 'User')) {
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.email"), ":$parameterName"));
            $orX->add($queryBuilder->expr()->like($ci("$rootAlias.fullName"), ":$parameterName"));
            $hasFields = true;
        }

        if (!$hasFields) {
            return;
        }

        $queryBuilder
            ->andWhere($orX)
            // Lowercase the search term too, so both sides of the LIKE match case-insensitively
            ->setParameter($parameterName, '%' . mb_strtolower($value, 'UTF-8') . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'q' => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'openapi' => [
                    'description' => 'Search across multiple fields (OR logic, case-insensitive)',
                ],
            ],
        ];
    }
}