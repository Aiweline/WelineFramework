<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\Search\Engine\MeilisearchEngine;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * 产品数据索引服务
 * 
 * 负责将产品数据同步到 Meilisearch 搜索引擎
 * 优化数据结构以提高搜索命中率
 */
class ProductIndexer
{
    /**
     * 批量索引大小
     */
    private const BATCH_SIZE = 100;
    
    /**
     * 索引产品数据到 Meilisearch
     * 
     * @param int|null $productId 产品ID，如果为null则索引所有产品
     * @param bool $forceReindex 是否强制重新索引
     * @return bool
     */
    public function indexProduct(?int $productId = null, bool $forceReindex = false): bool
    {
        try {
            /** @var MeilisearchEngine $engine */
            $engine = SearchEngineFactory::create();
            
            if (!$engine instanceof MeilisearchEngine) {
                w_log_info("当前搜索引擎不是 Meilisearch，无法执行索引操作");
                return false;
            }
            
            // 获取索引对象
            $index = $engine->getIndex();
            
            // 如果指定了产品ID，只索引该产品
            if ($productId !== null) {
                $product = $this->prepareProductData($productId);
                if ($product) {
                    $index->addDocuments([$product], 'product_id');
                    return true;
                }
                return false;
            }
            
            // 批量索引所有产品
            return $this->batchIndexProducts($index, $forceReindex);
            
        } catch (\Exception $e) {
            w_log_error("产品索引失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 批量索引产品
     * 
     * @param \Meilisearch\Index $index
     * @param bool $forceReindex
     * @return bool
     */
    private function batchIndexProducts(\Meilisearch\Index $index, bool $forceReindex = false): bool
    {
        try {
            // 如果强制重新索引，先删除所有文档
            if ($forceReindex) {
                $index->deleteAllDocuments();
            }
            
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            
            $page = 1;
            $totalIndexed = 0;
            
            while (true) {
                $productModel->clear();
                $productModel->pagination($page, self::BATCH_SIZE);
                $products = $productModel->select()->fetchArray();
                
                if (empty($products)) {
                    break;
                }
                
                // 准备批量数据
                $documents = [];
                foreach ($products as $product) {
                    $doc = $this->prepareProductDocument($product);
                    if ($doc) {
                        $documents[] = $doc;
                    }
                }
                
                if (!empty($documents)) {
                    // 批量添加文档
                    $index->addDocuments($documents, 'product_id');
                    $totalIndexed += count($documents);
                }
                
                $page++;
                
                // 避免无限循环
                if ($page > 1000) {
                    break;
                }
            }
            
            // 等待索引完成
            $this->waitForIndexing($index);
            
            return true;
            
        } catch (\Exception $e) {
            w_log_error("批量索引产品失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 准备产品文档数据（优化搜索命中率）
     * 
     * @param array $product 产品数据
     * @return array|null
     */
    private function prepareProductDocument(array $product): ?array
    {
        try {
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                return null;
            }
            
            // 获取产品分类
            $categoryIds = $this->getProductCategoryIds($productId);
            $categoryNames = $this->getCategoryNames($categoryIds);
            
            // 构建搜索优化的文档
            $document = [
                'product_id' => $productId,
                'name' => $product[Product::schema_fields_name] ?? '',
                'sku' => $product[Product::schema_fields_sku] ?? '',
                'spu' => $product[Product::schema_fields_spu] ?? '',
                'handle' => $product[Product::schema_fields_HANDLE] ?? '',
                'short_description' => $product[Product::schema_fields_short_description] ?? '',
                'description' => $product[Product::schema_fields_description] ?? '',
                'price' => (float)($product[Product::schema_fields_price] ?? 0),
                'cost' => (float)($product[Product::schema_fields_cost] ?? 0),
                'stock' => (int)($product[Product::schema_fields_stock] ?? 0),
                'status' => (int)($product[Product::schema_fields_status] ?? 0),
                'image' => $product[Product::schema_fields_image] ?? '',
                'weight' => (float)($product[Product::schema_fields_weight] ?? 0),
                'category_ids' => $categoryIds,
                'category_names' => $categoryNames,
                // 搜索关键词字段（合并多个字段，提高命中率）
                'searchable_text' => $this->buildSearchableText($product, $categoryNames),
                // Meta 信息
                'meta_title' => $product[Product::schema_fields_meta_name] ?? '',
                'meta_description' => $product[Product::schema_fields_meta_description] ?? '',
                'meta_keywords' => $product[Product::schema_fields_meta_keywords] ?? '',
            ];
            
            return $document;
            
        } catch (\Exception $e) {
            w_log_error("准备产品文档失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 准备单个产品数据
     * 
     * @param int $productId
     * @return array|null
     */
    private function prepareProductData(int $productId): ?array
    {
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->clear();
        $product = $productModel->load($productId)->getData();
        
        if (empty($product) || !$productModel->getId()) {
            return null;
        }
        
        return $this->prepareProductDocument($product);
    }
    
    /**
     * 获取产品分类ID数组
     * 
     * @param int $productId
     * @return array
     */
    private function getProductCategoryIds(int $productId): array
    {
        try {
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            return $productCategory->getCategoryIdsByProductId($productId);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取分类名称数组（通过 catalog 查询器，避免跨模块直接依赖）
     *
     * @param array $categoryIds
     * @return array
     */
    private function getCategoryNames(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        try {
            return w_query('catalog', 'getCategoryNames', ['category_ids' => $categoryIds]);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 构建可搜索文本（提高命中率）
     * 
     * @param array $product
     * @param array $categoryNames
     * @return string
     */
    private function buildSearchableText(array $product, array $categoryNames): string
    {
        $texts = [];
        
        // 产品名称
        if (!empty($product[Product::schema_fields_name])) {
            $texts[] = $product[Product::schema_fields_name];
        }
        
        // SKU
        if (!empty($product[Product::schema_fields_sku])) {
            $texts[] = $product[Product::schema_fields_sku];
        }
        
        // SPU
        if (!empty($product[Product::schema_fields_spu])) {
            $texts[] = $product[Product::schema_fields_spu];
        }
        
        // 简短描述
        if (!empty($product[Product::schema_fields_short_description])) {
            $texts[] = strip_tags($product[Product::schema_fields_short_description]);
        }
        
        // 描述（截取前500字符）
        if (!empty($product[Product::schema_fields_description])) {
            $description = strip_tags($product[Product::schema_fields_description]);
            $texts[] = mb_substr($description, 0, 500);
        }
        
        // 分类名称
        if (!empty($categoryNames)) {
            $texts = array_merge($texts, $categoryNames);
        }
        
        // Meta 关键词
        if (!empty($product[Product::schema_fields_meta_keywords])) {
            $texts[] = $product[Product::schema_fields_meta_keywords];
        }
        
        return implode(' ', $texts);
    }
    
    /**
     * 等待索引完成
     * 
     * @param \Meilisearch\Index $index
     * @param int $maxWaitTime 最大等待时间（秒）
     * @return void
     */
    private function waitForIndexing(\Meilisearch\Index $index, int $maxWaitTime = 30): void
    {
        $startTime = time();
        
        while (true) {
            try {
                $tasks = $index->getTasks(['statuses' => ['enqueued', 'processing']]);
                
                if (empty($tasks->getResults())) {
                    // 所有任务已完成
                    break;
                }
                
                // 检查超时
                if (time() - $startTime > $maxWaitTime) {
                    break;
                }
                
                // 等待1秒后重试
                sleep(1);
                
            } catch (\Exception $e) {
                // 如果获取任务失败，直接退出
                break;
            }
        }
    }
    
    /**
     * 删除产品索引
     * 
     * @param int $productId
     * @return bool
     */
    public function deleteProduct(int $productId): bool
    {
        try {
            /** @var MeilisearchEngine $engine */
            $engine = SearchEngineFactory::create();
            
            if (!$engine instanceof MeilisearchEngine) {
                return false;
            }
            
            $index = $engine->getIndex();
            $index->deleteDocument($productId);
            
            return true;
            
        } catch (\Exception $e) {
            w_log_error("删除产品索引失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 配置 Meilisearch 索引设置（优化搜索）
     * 
     * @return bool
     */
    public function configureIndex(): bool
    {
        try {
            /** @var MeilisearchEngine $engine */
            $engine = SearchEngineFactory::create();
            
            if (!$engine instanceof MeilisearchEngine) {
                return false;
            }
            
            $index = $engine->getIndex();
            
            // 配置可搜索字段（提高命中率）
            $index->updateSearchableAttributes([
                'name',
                'sku',
                'spu',
                'handle',
                'short_description',
                'description',
                'searchable_text',
                'category_names',
                'meta_keywords',
            ]);
            
            // 配置过滤字段
            $index->updateFilterableAttributes([
                'category_ids',
                'price',
                'status',
                'stock',
            ]);
            
            // 配置排序字段
            $index->updateSortableAttributes([
                'price',
                'product_id',
                'name',
            ]);
            
            // 配置排名规则（提高相关性）
            $index->updateRankingRules([
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ]);
            
            // 配置同义词（提高命中率）
            // 可以根据实际需求配置同义词
            
            return true;
            
        } catch (\Exception $e) {
            w_log_error("配置索引设置失败: " . $e->getMessage());
            return false;
        }
    }
}
