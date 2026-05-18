<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Api\SearchWritableEngineInterface;

/**
 * Meilisearch 搜索引擎适配器
 *
 * 使用 Meilisearch PHP SDK 实现搜索功能
 * 需要安装 composer require meilisearch/meilisearch-php
 */
class MeilisearchEngine implements SearchEngineInterface, SearchWritableEngineInterface
{
    private array $config = [];
    private ?\Meilisearch\Client $client = null;
    private ?\Meilisearch\Index $index = null;

    /**
     * Meilisearch 索引名称
     */
    private const INDEX_NAME = 'products';

    /**
     * @inheritDoc
     */
    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        try {
            $this->ensureClient();
            $this->ensureIndex();

            $searchParams = [
                'page' => $page,
                'hitsPerPage' => $pageSize,
            ];

            $filterArray = [];

            if (!empty($filters['category_id'])) {
                $filterArray[] = 'category_ids = ' . (int)$filters['category_id'];
            }

            if (!empty($filters['price_min']) || !empty($filters['price_max'])) {
                $priceFilter = [];
                if (!empty($filters['price_min'])) {
                    $priceFilter[] = 'price >= ' . (float)$filters['price_min'];
                }
                if (!empty($filters['price_max'])) {
                    $priceFilter[] = 'price <= ' . (float)$filters['price_max'];
                }
                if (!empty($priceFilter)) {
                    $filterArray[] = '(' . \implode(' AND ', $priceFilter) . ')';
                }
            }

            $filterArray[] = 'status = 1';

            if (!empty($filterArray)) {
                $searchParams['filter'] = \implode(' AND ', $filterArray);
            }

            if (!empty($filters['order_by'])) {
                $orderBy = $filters['order_by'];
                $orderDir = $filters['order_dir'] ?? 'asc';
                $searchParams['sort'] = [$orderBy . ':' . $orderDir];
            }

            $keyword = \trim($keyword);
            $results = $this->index->search($keyword, $searchParams);

            return [
                'items' => $results->getHits(),
                'total' => $results->getEstimatedTotalHits(),
            ];
        } catch (\Exception $e) {
            w_log_warning('Meilisearch 搜索失败: ' . $e->getMessage());
            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
        }
    }

    /**
     * @inheritDoc
     */
    public function getSuggestions(string $keyword, int $limit = 10): array
    {
        try {
            $this->ensureClient();
            $this->ensureIndex();

            $keyword = \trim($keyword);
            if ($keyword === '') {
                return [];
            }

            $results = $this->index->search($keyword, [
                'hitsPerPage' => $limit,
                'attributesToRetrieve' => ['product_id', 'name', 'sku'],
            ]);

            $suggestions = [];
            foreach ($results->getHits() as $hit) {
                $suggestions[] = [
                    'text' => $hit['name'] ?? '',
                    'type' => 'product',
                    'icon' => 'fa-shopping-bag',
                    'url' => '/product/view?id=' . ($hit['product_id'] ?? ''),
                ];
            }

            return $suggestions;
        } catch (\Exception $e) {
            w_log_warning('Meilisearch 获取建议失败: ' . $e->getMessage());

            $suggestions = [];
            $productSuggestions = w_query('product', 'getProductSuggestions', [
                'keyword' => $keyword,
                'limit' => \min(5, $limit),
            ]);
            if (\is_array($productSuggestions)) {
                $suggestions = \array_merge($suggestions, $productSuggestions);
            }

            if (\count($suggestions) < $limit) {
                $categorySuggestions = w_query('catalog', 'getCategorySuggestions', [
                    'keyword' => $keyword,
                    'limit' => \min(3, $limit - \count($suggestions)),
                ]);
                if (\is_array($categorySuggestions)) {
                    $suggestions = \array_merge($suggestions, $categorySuggestions);
                }
            }

            return \array_slice($suggestions, 0, $limit);
        }
    }

    /**
     * @inheritDoc
     */
    public function initConfig(array $config): bool
    {
        $this->config = \array_merge([
            'host' => 'http://127.0.0.1:7700',
            'api_key' => null,
            'index_name' => self::INDEX_NAME,
        ], $config);

        $this->client = null;
        $this->index = null;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        try {
            $this->ensureClient();

            $health = $this->client->health();
            return isset($health['status']) && $health['status'] === 'available';
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getEngineType(): string
    {
        return 'meilisearch';
    }

    /**
     * @inheritDoc
     */
    public function getEngineName(): string
    {
        return 'Meilisearch';
    }

    public function upsertDocuments(array $documents, string $primaryKey = 'document_id'): bool
    {
        try {
            $this->ensureIndex();
            $this->index->addDocuments($documents, $primaryKey);

            return true;
        } catch (\Throwable $throwable) {
            w_log_error('Meilisearch 写入文档失败: ' . $throwable->getMessage());

            return false;
        }
    }

    public function deleteDocuments(array $documentIds): bool
    {
        try {
            $this->ensureIndex();
            $this->index->deleteDocuments(array_values(array_filter(array_map('strval', $documentIds))));

            return true;
        } catch (\Throwable $throwable) {
            w_log_error('Meilisearch 删除文档失败: ' . $throwable->getMessage());

            return false;
        }
    }

    public function deleteByDocumentType(string $documentType): bool
    {
        try {
            $this->ensureIndex();
            $this->index->deleteDocuments(['filter' => 'document_type = "' . addslashes($documentType) . '"']);

            return true;
        } catch (\Throwable $throwable) {
            w_log_error('Meilisearch 按文档类型删除失败: ' . $throwable->getMessage());

            return false;
        }
    }

    public function configureIndex(array $definition = []): bool
    {
        try {
            $this->ensureIndex();
            $searchableAttributes = array_values(array_unique(array_filter(array_map('strval', $definition['searchable_fields'] ?? [
                'name',
                'sku',
                'spu',
                'handle',
                'short_description',
                'description',
                'searchable_text',
                'category_names',
            ]))));
            $filterableAttributes = array_values(array_unique(array_filter(array_map('strval', $definition['filterable_fields'] ?? [
                'document_type',
                'category_ids',
                'price',
                'status',
                'stock',
            ]))));
            $sortableAttributes = array_values(array_unique(array_filter(array_map('strval', $definition['sortable_fields'] ?? [
                'price',
                'name',
                'entity_id',
            ]))));

            $this->index->updateSearchableAttributes($searchableAttributes);
            $this->index->updateFilterableAttributes($filterableAttributes);
            $this->index->updateSortableAttributes($sortableAttributes);

            return true;
        } catch (\Throwable $throwable) {
            w_log_error('Meilisearch 配置索引失败: ' . $throwable->getMessage());

            return false;
        }
    }

    /**
     * 确保客户端已初始化
     *
     * @throws \Exception
     */
    private function ensureClient(): void
    {
        if ($this->client === null) {
            if (!\class_exists(\Meilisearch\Client::class)) {
                throw new \Exception('Meilisearch PHP SDK 未安装，请运行 composer require meilisearch/meilisearch-php');
            }

            $host = $this->config['host'] ?? 'http://127.0.0.1:7700';
            $apiKey = $this->config['api_key'] ?? null;

            $this->client = new \Meilisearch\Client($host, $apiKey);
        }
    }

    /**
     * 确保索引已初始化
     *
     * @throws \Exception
     */
    private function ensureIndex(): void
    {
        if ($this->index === null) {
            $this->ensureClient();

            $indexName = $this->config['index_name'] ?? self::INDEX_NAME;
            $this->index = $this->client->index($indexName);
        }
    }

    /**
     * 回退搜索（使用数据库）
     */
    private function fallbackSearch(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => $keyword,
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        return \is_array($result) ? $result : ['items' => [], 'total' => 0, 'pagination' => ''];
    }

    /**
     * 获取 Meilisearch 客户端（用于索引操作）
     *
     * @throws \Exception
     */
    public function getClient(): \Meilisearch\Client
    {
        $this->ensureClient();
        return $this->client;
    }

    /**
     * 获取索引对象（用于索引操作）
     *
     * @throws \Exception
     */
    public function getIndex(): \Meilisearch\Index
    {
        $this->ensureIndex();
        return $this->index;
    }
}
