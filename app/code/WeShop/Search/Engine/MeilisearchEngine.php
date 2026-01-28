<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Catalog\Model\Category;

/**
 * Meilisearch 搜索引擎适配器
 * 
 * 使用 Meilisearch PHP SDK 实现搜索功能
 * 需要安装: composer require meilisearch/meilisearch-php
 */
class MeilisearchEngine implements SearchEngineInterface
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
            
            // 构建搜索参数
            $searchParams = [
                'page' => $page,
                'hitsPerPage' => $pageSize,
            ];
            
            // 添加过滤条件
            $filterArray = [];
            
            // 分类过滤
            if (!empty($filters['category_id'])) {
                $filterArray[] = 'category_ids = ' . (int)$filters['category_id'];
            }
            
            // 价格范围过滤
            if (!empty($filters['price_min']) || !empty($filters['price_max'])) {
                $priceFilter = [];
                if (!empty($filters['price_min'])) {
                    $priceFilter[] = 'price >= ' . (float)$filters['price_min'];
                }
                if (!empty($filters['price_max'])) {
                    $priceFilter[] = 'price <= ' . (float)$filters['price_max'];
                }
                if (!empty($priceFilter)) {
                    $filterArray[] = '(' . implode(' AND ', $priceFilter) . ')';
                }
            }
            
            // 状态过滤（只搜索上架产品）
            $filterArray[] = 'status = 1';
            
            if (!empty($filterArray)) {
                $searchParams['filter'] = implode(' AND ', $filterArray);
            }
            
            // 排序
            if (!empty($filters['order_by'])) {
                $orderBy = $filters['order_by'];
                $orderDir = $filters['order_dir'] ?? 'asc';
                $searchParams['sort'] = [$orderBy . ':' . $orderDir];
            }
            
            // 执行搜索
            $keyword = trim($keyword);
            $results = $this->index->search($keyword, $searchParams);
            
            return [
                'items' => $results->getHits(),
                'total' => $results->getEstimatedTotalHits(),
            ];
            
        } catch (\Exception $e) {
            error_log("Meilisearch 搜索失败: " . $e->getMessage());
            // 回退到数据库搜索
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
            
            $keyword = trim($keyword);
            if (empty($keyword)) {
                return [];
            }
            
            // 使用 Meilisearch 的搜索建议功能
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
            error_log("Meilisearch 获取建议失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function initConfig(array $config): bool
    {
        $this->config = array_merge([
            'host' => 'http://127.0.0.1:7700',
            'api_key' => null,
            'index_name' => self::INDEX_NAME,
        ], $config);
        
        // 重置客户端，以便使用新配置
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
            
            // 测试连接
            $health = $this->client->health();
            return isset($health['status']) && $health['status'] === 'available';
            
        } catch (\Exception $e) {
            error_log("Meilisearch 连接测试失败: " . $e->getMessage());
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
    
    /**
     * 确保客户端已初始化
     * 
     * @return void
     * @throws \Exception
     */
    private function ensureClient(): void
    {
        if ($this->client === null) {
            if (!class_exists(\Meilisearch\Client::class)) {
                throw new \Exception('Meilisearch PHP SDK 未安装，请运行: composer require meilisearch/meilisearch-php');
            }
            
            $host = $this->config['host'] ?? 'http://127.0.0.1:7700';
            $apiKey = $this->config['api_key'] ?? null;
            
            $this->client = new \Meilisearch\Client($host, $apiKey);
        }
    }
    
    /**
     * 确保索引已初始化
     * 
     * @return void
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
     * 
     * @param string $keyword
     * @param array $filters
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    private function fallbackSearch(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->clear();
        
        // 关键词搜索
        if (!empty($keyword)) {
            $keyword = trim($keyword);
            $product->where(Product::fields_name, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::fields_sku, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::fields_short_description, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::fields_description, ['like', '%' . $keyword . '%'], 'or');
        }
        
        // 应用过滤条件
        if (!empty($filters['category_id'])) {
            $product->where('category_id', $filters['category_id']);
        }
        
        if (!empty($filters['price_min'])) {
            $product->where(Product::fields_price, ['>=', $filters['price_min']]);
        }
        
        if (!empty($filters['price_max'])) {
            $product->where(Product::fields_price, ['<=', $filters['price_max']]);
        }
        
        // 只搜索上架的产品
        $product->where(Product::fields_status, 1);
        
        // 排序
        $orderBy = $filters['order_by'] ?? Product::fields_ID;
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $product->order($orderBy, $orderDir);
        
        // 分页
        $product->pagination($page, $pageSize);
        $items = $product->select()->fetchArray();
        
        return [
            'items' => $items,
            'total' => $product->getTotalCount(),
        ];
    }
    
    /**
     * 获取 Meilisearch 客户端（用于索引操作）
     * 
     * @return \Meilisearch\Client
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
     * @return \Meilisearch\Index
     * @throws \Exception
     */
    public function getIndex(): \Meilisearch\Index
    {
        $this->ensureIndex();
        return $this->index;
    }
}
