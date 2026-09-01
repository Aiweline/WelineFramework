<?php

declare(strict_types=1);

namespace Weline\Search\Api;

use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

interface SearchProviderIndexStorageInterface
{
    public function upsert(IndexDocument $document): void;

    public function delete(string $indexer, int $websiteId, string $entityId): void;

    /**
     * Replace all documents for one indexer + website scope.
     *
     * @param list<IndexDocument> $documents
     */
    public function replaceIndexerWebsite(string $indexer, int $websiteId, array $documents): int;

    /**
     * @return list<array<string,mixed>>
     */
    public function search(
        string $indexer,
        SearchRequest $request,
        SearchExpression $expression,
    ): array;

    public function countIndexer(string $indexer, int $websiteId = -1): int;
}
