<?php

declare(strict_types=1);

namespace WeShop\Filters\Adapter;

use WeShop\Filters\Api\FilterAdapterInterface;
use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Model\FilterResult;
use WeShop\Search\Service\SearchEngineFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Manticore Search 筛选适配器
 * 
 * 使用 Manticore Search 进行高性能筛选和 Facet 聚合
 */
class ManticoreFilterAdapter implements FilterAdapterInterface
{
    /**
     * @var string Manticore 索引名称
     */
    private string $indexName = 'products';
    
    /**
     * @var SearchEngineFactory|null
     */
    private ?SearchEngineFactory $searchEngineFactory = null;
    
    /**
     * @var bool 是否可用
     */
    private ?bool $available = null;
    
    /**
     * @inheritDoc
     */
    public function filter(
        int $categoryId,
        array $productIds,
        array $filters,
        array $facetFields = []
    ): FilterResultInterface {
        if (!$this->isAvailable() || empty($productIds)) {
            return $this->createEmptyResult($productIds);
        }
        
        try {
            // 构建 Manticore 查询
            $query = $this->buildQuery($categoryId, $productIds, $filters);
            
            // 执行查询
            $searchResult = $this->executeQuery($query, $facetFields);
            
            // 解析结果
            return $this->parseResult($searchResult, $productIds);
        } catch (\Throwable $e) {
            // 如果 Manticore 查询失败，返回空结果
            return $this->createEmptyResult($productIds);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'manticore';
    }
    
    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        
        try {
            // 检查 SearchEngineFactory 是否存在且 Manticore 配置可用
            if (!class_exists(SearchEngineFactory::class)) {
                $this->available = false;
                return false;
            }
            
            $this->searchEngineFactory = ObjectManager::getInstance(SearchEngineFactory::class);
            
            // 尝试获取 Manticore 引擎
            $engine = $this->searchEngineFactory->getEngine('manticore');
            $this->available = $engine !== null && $engine->isAvailable();
        } catch (\Throwable $e) {
            $this->available = false;
        }
        
        return $this->available;
    }
    
    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 100; // 高优先级
    }
    
    /**
     * 构建 Manticore 查询
     */
    private function buildQuery(int $categoryId, array $productIds, array $filters): array
    {
        $query = [
            'index' => $this->indexName,
            'query' => [
                'bool' => [
                    'must' => [
                        // 限制在指定产品ID内
                        ['in' => ['product_id' => $productIds]],
                    ],
                ],
            ],
        ];
        
        // 添加分类条件
        if ($categoryId > 0) {
            $query['query']['bool']['must'][] = [
                'equals' => ['category_id' => $categoryId],
            ];
        }
        
        // 添加筛选条件
        foreach ($filters as $field => $values) {
            if (empty($values)) {
                continue;
            }
            
            if (!is_array($values)) {
                $values = [$values];
            }
            
            // 处理价格范围筛选
            if ($field === 'price' && count($values) === 1) {
                $priceRange = $this->parsePriceRange($values[0]);
                if ($priceRange) {
                    $rangeFilter = ['range' => ['price' => []]];
                    if ($priceRange['min'] !== null) {
                        $rangeFilter['range']['price']['gte'] = $priceRange['min'];
                    }
                    if ($priceRange['max'] !== null) {
                        $rangeFilter['range']['price']['lte'] = $priceRange['max'];
                    }
                    $query['query']['bool']['must'][] = $rangeFilter;
                    continue;
                }
            }
            
            // 普通字段筛选（OR 关系）
            $query['query']['bool']['must'][] = [
                'in' => [$field => $values],
            ];
        }
        
        return $query;
    }
    
    /**
     * 执行 Manticore 查询
     */
    private function executeQuery(array $query, array $facetFields): array
    {
        // 添加 Facet 聚合
        if (!empty($facetFields)) {
            $query['aggs'] = [];
            foreach ($facetFields as $field) {
                $query['aggs'][$field] = [
                    'terms' => [
                        'field' => $field,
                        'size' => 100,
                    ],
                ];
            }
        }
        
        // 设置返回字段
        $query['_source'] = ['product_id'];
        $query['limit'] = 10000; // 最大返回数量
        
        // 通过 SearchEngineFactory 执行查询
        if ($this->searchEngineFactory) {
            $engine = $this->searchEngineFactory->getEngine('manticore');
            if ($engine) {
                return $engine->search($query);
            }
        }
        
        return ['hits' => [], 'aggregations' => []];
    }
    
    /**
     * 解析 Manticore 结果
     */
    private function parseResult(array $searchResult, array $originalProductIds): FilterResultInterface
    {
        $productIds = [];
        $facets = [];
        
        // 解析产品ID
        if (isset($searchResult['hits']['hits'])) {
            foreach ($searchResult['hits']['hits'] as $hit) {
                $productId = $hit['_source']['product_id'] ?? null;
                if ($productId !== null) {
                    $productIds[] = (int)$productId;
                }
            }
        }
        
        // 解析 Facet 聚合
        if (isset($searchResult['aggregations'])) {
            foreach ($searchResult['aggregations'] as $field => $agg) {
                $facets[$field] = [];
                if (isset($agg['buckets'])) {
                    foreach ($agg['buckets'] as $bucket) {
                        $facets[$field][$bucket['key']] = $bucket['doc_count'];
                    }
                }
            }
        }
        
        /** @var FilterResult $result */
        $result = ObjectManager::getInstance(FilterResult::class);
        $result->setProductIds($productIds)
            ->setOriginalCount(count($originalProductIds))
            ->setFilters([]) // Facets 数据会通过其他方式处理
            ->setAppliedFilters([]);
        
        return $result;
    }
    
    /**
     * 解析价格范围字符串
     */
    private function parsePriceRange(string $value): ?array
    {
        if (preg_match('/^([\d.]+)-([\d.]*)$/', $value, $matches)) {
            return [
                'min' => (float)$matches[1],
                'max' => $matches[2] !== '' ? (float)$matches[2] : null,
            ];
        }
        return null;
    }
    
    /**
     * 创建空结果
     */
    private function createEmptyResult(array $productIds): FilterResultInterface
    {
        /** @var FilterResult $result */
        $result = ObjectManager::getInstance(FilterResult::class);
        $result->setProductIds($productIds)
            ->setOriginalCount(count($productIds))
            ->setFilters([])
            ->setAppliedFilters([]);
        
        return $result;
    }
    
    /**
     * 设置索引名称
     */
    public function setIndexName(string $indexName): self
    {
        $this->indexName = $indexName;
        return $this;
    }
}
