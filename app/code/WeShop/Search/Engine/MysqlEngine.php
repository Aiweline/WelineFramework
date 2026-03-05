<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Catalog\Model\Category;

/**
 * MySQL搜索引擎适配器
 */
class MysqlEngine implements SearchEngineInterface
{
    private array $config = [];
    
    /**
     * @inheritDoc
     */
    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->clear();
        
        // 关键词搜索 - 支持多字段搜索
        if (!empty($keyword)) {
            $keyword = trim($keyword);
            $product->where(Product::schema_fields_name, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_sku, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_short_description, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_description, ['like', '%' . $keyword . '%'], 'or');
        }
        
        // 应用过滤条件
        if (!empty($filters['category_id'])) {
            $product->where('category_id', $filters['category_id']);
        }
        
        if (!empty($filters['price_min'])) {
            $product->where(Product::schema_fields_price, ['>=', $filters['price_min']]);
        }
        
        if (!empty($filters['price_max'])) {
            $product->where(Product::schema_fields_price, ['<=', $filters['price_max']]);
        }
        
        // 只搜索上架的产品
        $product->where(Product::schema_fields_status, 1);
        
        // 排序
        $orderBy = $filters['order_by'] ?? Product::schema_fields_ID;
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
     * @inheritDoc
     */
    public function getSuggestions(string $keyword, int $limit = 10): array
    {
        $suggestions = [];
        
        // 从产品名称获取建议
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->clear();
        $product->where(Product::schema_fields_name, ['like', '%' . $keyword . '%'])
            ->where(Product::schema_fields_status, 1)
            ->order(Product::schema_fields_ID, 'DESC')
            ->limit(min(5, $limit));
        
        $products = $product->select()->fetchArray();
        foreach ($products as $item) {
            $suggestions[] = [
                'text' => $item[Product::schema_fields_name],
                'type' => 'product',
                'icon' => 'fa-shopping-bag',
                'url' => '/product/view?id=' . $item[Product::schema_fields_ID],
            ];
        }
        
        // 从分类名称获取建议
        if (count($suggestions) < $limit) {
            /** @var Category $category */
            $category = ObjectManager::getInstance(Category::class);
            $category->clear();
            $category->where(Category::schema_fields_NAME, ['like', '%' . $keyword . '%'])
                ->where(Category::schema_fields_IS_ACTIVE, 1)
                ->order(Category::schema_fields_ID, 'DESC')
                ->limit(min(3, $limit - count($suggestions)));
            
            $categories = $category->select()->fetchArray();
            foreach ($categories as $item) {
                $suggestions[] = [
                    'text' => $item[Category::schema_fields_NAME],
                    'type' => 'category',
                    'icon' => 'fa-folder',
                    'url' => '/catalog/category/view?id=' . $item[Category::schema_fields_ID],
                ];
            }
        }
        
        return array_slice($suggestions, 0, $limit);
    }
    
    /**
     * @inheritDoc
     */
    public function initConfig(array $config): bool
    {
        $this->config = $config;
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        // MySQL连接测试（通过ORM测试）
        try {
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->clear()->limit(1)->select()->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getEngineType(): string
    {
        return 'mysql';
    }
    
    /**
     * @inheritDoc
     */
    public function getEngineName(): string
    {
        return 'MySQL全文搜索';
    }
}
