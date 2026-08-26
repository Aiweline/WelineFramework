<?php

declare(strict_types=1);

namespace Weline\Search\Engine;

use Weline\Search\Api\SearchEngineInterface;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

/**
 * Default engine: provider-driven SQL (no full-table PHP scan in the engine).
 */
class MysqlSearchEngine implements SearchEngineInterface
{
    public function code(): string
    {
        return 'mysql';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return [
            'fulltext' => true,
            'suggest' => true,
            'in_memory' => false,
            'remote' => false,
        ];
    }

    public function search(
        SearchExpression $expression,
        SearchRequest $request,
        SearchProviderInterface $provider,
    ): array {
        // Provider.execute() owns SQL; engine returns empty when called directly.
        return [];
    }

    public function upsert(array $documents): int
    {
        return 0;
    }

    public function delete(array $keys): int
    {
        return 0;
    }
}
