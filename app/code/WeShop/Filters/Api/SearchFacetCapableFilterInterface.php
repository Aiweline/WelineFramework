<?php

declare(strict_types=1);

namespace WeShop\Filters\Api;

interface SearchFacetCapableFilterInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function getSearchFacetDefinition(int $categoryId, array $context = []): ?array;

    /**
     * @param array<int, array<string, mixed>> $buckets
     * @param array<string, mixed> $appliedFilters
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSearchFacetBuckets(array $buckets, array $appliedFilters = [], array $context = []): array;
}
