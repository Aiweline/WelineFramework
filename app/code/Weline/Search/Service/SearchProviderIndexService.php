<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchProviderIndexStorageInterface;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;

/**
 * Universal provider content index: rebuild + incremental upsert + indexed search.
 */
final class SearchProviderIndexService
{
    public function __construct(
        private readonly SearchProviderIndexStorageInterface $store,
        private readonly SearchProviderRegistry $registry,
    ) {
    }

    public function upsert(IndexDocument $document): void
    {
        $this->store->upsert($document);
    }

    public function delete(string $indexer, int $websiteId, string $entityId): void
    {
        $this->store->delete($indexer, $websiteId, $entityId);
    }

    public function rebuild(string $indexer, int $websiteId = 0): int
    {
        $provider = $this->registry->get($indexer);
        if ($provider === null) {
            return 0;
        }

        $request = new SearchRequest(
            q: '',
            type: $indexer,
            page: 1,
            pageSize: 10000,
            websiteId: $websiteId,
            storeId: 0,
            channelId: 0,
            locale: '',
            currency: '',
            extras: [],
        );
        $documents = $provider->documentsForIndex($request);

        return $this->store->replaceIndexerWebsite($indexer, $websiteId, $documents);
    }

    public function rebuildAll(int $websiteId = 0): int
    {
        $total = 0;
        foreach ($this->registry->all() as $code => $provider) {
            $request = new SearchRequest(
                q: '',
                type: $code,
                page: 1,
                pageSize: 10000,
                websiteId: $websiteId,
                storeId: 0,
                channelId: 0,
                locale: '',
                currency: '',
                extras: [],
            );
            $documents = $provider->documentsForIndex($request);
            if ($documents === []) {
                continue;
            }
            $total += $this->store->replaceIndexerWebsite($code, $websiteId, $documents);
        }

        return $total;
    }

    public function search(
        SearchRequest $request,
        SearchExpression $expression,
        SearchProviderInterface $provider,
    ): SearchResult {
        $rows = $this->store->search($provider->code(), $request, $expression);
        $categoryId = 0;
        if (isset($request->extras['category_id'])) {
            $categoryId = (int)$request->extras['category_id'];
        } elseif (isset($request->extras['blog_category_id'])) {
            $categoryId = (int)$request->extras['blog_category_id'];
        }
        if ($categoryId > 0) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($categoryId): bool {
                    $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                    $rowCategory = (int)($payload['category_id'] ?? $payload['blog_category_id'] ?? 0);

                    return $rowCategory === $categoryId;
                },
            ));
        }

        $hits = [];
        foreach ($rows as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if ($payload === []) {
                $payload = [
                    'title' => (string)($row['title'] ?? ''),
                    'url' => (string)($row['url'] ?? ''),
                    'entity_id' => (string)($row['entity_id'] ?? ''),
                ];
            }
            // Ensure hit template can always read title/url from payload.
            if (!isset($payload['title']) || trim((string)$payload['title']) === '') {
                $payload['title'] = (string)($row['title'] ?? '');
            }
            if (!isset($payload['url']) || trim((string)$payload['url']) === '') {
                $payload['url'] = (string)($row['url'] ?? '');
            }
            $hits[] = new SearchHit(
                indexer: $provider->code(),
                entityType: (string)($row['entity_type'] ?? $provider->code()),
                entityId: (string)($row['entity_id'] ?? ''),
                title: (string)($row['title'] ?? $payload['title'] ?? ''),
                url: (string)($row['url'] ?? $payload['url'] ?? ''),
                payload: $payload,
            );
        }

        return new SearchResult(
            ok: true,
            type: $provider->code(),
            hits: $hits,
            hitCount: count($hits),
            engine: 'provider_index',
        );
    }

    public function isIndexed(string $indexer, int $websiteId = 0): bool
    {
        if ($this->store->countIndexer($indexer, $websiteId) > 0) {
            return true;
        }

        return $websiteId > 0 && $this->store->countIndexer($indexer, 0) > 0;
    }
}
