<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchEngineInterface;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\SearchExpression;

abstract class AbstractSearchProvider implements SearchProviderInterface
{
    public function sortOrder(): int
    {
        return 100;
    }

    public function hitTemplate(): string
    {
        return '';
    }

    public function documentsForIndex(SearchRequest $request): array
    {
        return [];
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        $engine = $this->resolveEngine();
        $hits = $engine->search($expression, $request, $this);

        return new SearchResult(
            ok: true,
            type: $this->code(),
            hits: $hits,
            hitCount: count($hits),
            engine: $engine->code(),
        );
    }

    protected function resolveEngine(): SearchEngineInterface
    {
        return ObjectManagerBridge::engineResolver()->resolve();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<SearchHit>
     */
    protected function mapLegacyHits(array $rows, string $indexer): array
    {
        $hits = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entityId = trim((string)($row['entity_id'] ?? ''));
            $title = trim((string)($row['title'] ?? $row['name'] ?? ''));
            if ($entityId === '' && $title === '') {
                continue;
            }
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '') {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug !== '') {
                    $url = 'product/' . $slug;
                } elseif ($entityId !== '') {
                    $url = 'product/' . $entityId;
                }
            }
            $hits[] = new SearchHit(
                indexer: $indexer,
                entityType: trim((string)($row['entity_type'] ?? $indexer)),
                entityId: $entityId,
                title: $title,
                url: $url,
                payload: $row,
                score: (float)($row['score'] ?? 0.0),
            );
        }

        return $hits;
    }
}
