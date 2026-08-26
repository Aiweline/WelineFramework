<?php

declare(strict_types=1);

namespace Weline\Search\Api;

use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

interface SearchEngineInterface
{
    public function code(): string;

    /** False when remote engine is not configured (fail-closed). */
    public function isAvailable(): bool;

    /**
     * @return array{fulltext?:bool,suggest?:bool,in_memory?:bool,remote?:bool,skeleton?:bool}
     */
    public function capabilities(): array;

    /**
     * @return list<SearchHit>
     */
    public function search(
        SearchExpression $expression,
        SearchRequest $request,
        SearchProviderInterface $provider,
    ): array;

    /**
     * @param list<IndexDocument> $documents
     */
    public function upsert(array $documents): int;

    /**
     * @param list<array{indexer:string,entity_type:string,entity_id:string,website_id:int,store_id:int,channel_id:int}> $keys
     */
    public function delete(array $keys): int;
}
