<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Catalog\Model\Category;
use WeShop\Search\Model\SearchHistory;
use WeShop\Search\Api\SearchEngineInterface;

/**
 * 搜索服务
 */
class SearchService
{
    /**
     * 搜索产品
     * 
     * @param string $keyword 关键词
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param string $scope 作用域
     * @return array
     */
    public function searchProducts(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20, string $scope = 'default'): array
    {
        // 使用可配置的搜索引擎
        $engine = SearchEngineFactory::create($scope);
        
        if (!$engine) {
            // 如果无法创建引擎，回退到默认MySQL搜索
            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
        }
        
        // 使用配置的搜索引擎进行搜索
        $result = $engine->search($keyword, $filters, $page, $pageSize);
        
        $total = $result['total'] ?? 0;
        
        // 记录搜索历史
        if (!empty($keyword)) {
            /** @var SearchHistory $searchHistory */
            $searchHistory = ObjectManager::getInstance(SearchHistory::class);
            $userId = $this->getCurrentUserId();
            $searchHistory->recordSearch($keyword, $total, $userId);
        }
        
        // 如果是MySQL引擎，需要获取分页HTML
        $pagination = '';
        if ($engine->getEngineType() === 'mysql') {
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $pagination = $product->getPagination();
        }
        
        return [
            'items' => $result['items'] ?? [],
            'total' => $total,
            'pagination' => $pagination,
            'keyword' => $keyword,
            'engine' => $engine->getEngineType(),
        ];
    }
    
    /**
     * 回退搜索（默认MySQL搜索）
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
        
        // 关键词搜索 - 支持多字段搜索
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
            'pagination' => $product->getPagination(),
            'keyword' => $keyword,
            'engine' => 'mysql',
        ];
    }
    
    /**
     * 获取搜索建议
     * 
     * @param string $keyword 关键词
     * @param int $limit 返回数量
     * @param string $scope 作用域
     * @return array
     */
    public function getSearchSuggestions(string $keyword, int $limit = 10, string $scope = 'default'): array
    {
        if (empty(trim($keyword))) {
            return [];
        }
        
        $keyword = trim($keyword);
        
        // 使用可配置的搜索引擎获取建议
        $engine = SearchEngineFactory::create($scope);
        
        if ($engine) {
            $engineSuggestions = $engine->getSuggestions($keyword, $limit);
            if (!empty($engineSuggestions)) {
                return $engineSuggestions;
            }
        }
        
        // 如果引擎没有返回建议，使用默认逻辑
        $suggestions = [];
        
        // 1. 从产品名称获取建议
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->clear();
        $product->where(Product::fields_name, ['like', '%' . $keyword . '%'])
            ->where(Product::fields_status, 1)
            ->order(Product::fields_ID, 'DESC')
            ->limit(min(5, $limit));
        
        $products = $product->select()->fetchArray();
        foreach ($products as $item) {
            $suggestions[] = [
                'text' => $item[Product::fields_name],
                'type' => 'product',
                'icon' => 'fa-shopping-bag',
                'url' => '/product/view?id=' . $item[Product::fields_ID],
            ];
        }
        
        // 2. 从分类名称获取建议
        if (count($suggestions) < $limit) {
            /** @var Category $category */
            $category = ObjectManager::getInstance(Category::class);
            $category->clear();
            $category->where(Category::fields_NAME, ['like', '%' . $keyword . '%'])
                ->where(Category::fields_IS_ACTIVE, 1)
                ->order(Category::fields_ID, 'DESC')
                ->limit(min(3, $limit - count($suggestions)));
            
            $categories = $category->select()->fetchArray();
            foreach ($categories as $item) {
                $suggestions[] = [
                    'text' => $item[Category::fields_NAME],
                    'type' => 'category',
                    'icon' => 'fa-folder',
                    'url' => '/catalog/category/view?id=' . $item[Category::fields_ID],
                ];
            }
        }
        
        // 3. 从搜索历史获取建议（如果还有剩余位置）
        if (count($suggestions) < $limit) {
            /** @var SearchHistory $searchHistory */
            $searchHistory = ObjectManager::getInstance(SearchHistory::class);
            $searchHistory->clear();
            $searchHistory->where(SearchHistory::fields_KEYWORD, ['like', '%' . $keyword . '%'])
                ->order(SearchHistory::fields_SEARCH_COUNT, 'DESC')
                ->limit(min(3, $limit - count($suggestions)));
            
            $histories = $searchHistory->select()->fetchArray();
            foreach ($histories as $item) {
                // 避免重复
                $exists = false;
                foreach ($suggestions as $suggestion) {
                    if ($suggestion['text'] === $item[SearchHistory::fields_KEYWORD]) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $suggestions[] = [
                        'text' => $item[SearchHistory::fields_KEYWORD],
                        'type' => 'history',
                        'icon' => 'fa-history',
                        'url' => '/search/index?q=' . urlencode($item[SearchHistory::fields_KEYWORD]),
                    ];
                }
            }
        }
        
        // 限制返回数量
        return array_slice($suggestions, 0, $limit);
    }
    
    /**
     * 获取热门搜索词
     * 
     * @param int $limit 返回数量
     * @return array
     */
    public function getPopularKeywords(int $limit = 10): array
    {
        /** @var SearchHistory $searchHistory */
        $searchHistory = ObjectManager::getInstance(SearchHistory::class);
        return $searchHistory->getPopularKeywords($limit);
    }
    
    /**
     * 获取当前用户ID
     * 
     * @return int|null
     */
    private function getCurrentUserId(): ?int
    {
        // TODO: 从会话或认证系统获取当前用户ID
        // 这里暂时返回null，实际使用时需要根据框架的认证系统实现
        return null;
    }
}
