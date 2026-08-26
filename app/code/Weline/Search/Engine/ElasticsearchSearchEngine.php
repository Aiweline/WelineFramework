<?php

declare(strict_types=1);

namespace Weline\Search\Engine;

use Weline\Search\Api\SearchEngineInterface;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

/** Elasticsearch skeleton: unavailable until configured. */
class ElasticsearchSearchEngine implements SearchEngineInterface
{
    public function code(): string
    {
        return 'elasticsearch';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return ['fulltext' => true, 'remote' => true, 'skeleton' => true];
    }

    public function search(
        SearchExpression $expression,
        SearchRequest $request,
        SearchProviderInterface $provider,
    ): array {
        throw new \RuntimeException('Elasticsearch search engine is not configured');
    }

    public function upsert(array $documents): int
    {
        throw new \RuntimeException('Elasticsearch search engine is not configured');
    }

    public function delete(array $keys): int
    {
        throw new \RuntimeException('Elasticsearch search engine is not configured');
    }
}
