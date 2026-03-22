<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Frontend\Search;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Search\Service\SearchService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索页面控制器
 */
class Index extends BaseController
{
    protected ?string $layoutType = 'search';

    /**
     * 搜索页面
     */
    public function index(): string
    {
        $keyword = $this->request->getParam('q') ?? '';
        $page = (int)($this->request->getParam('page') ?? 1);
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        
        $filters = [
            'category_id' => $this->request->getParam('category_id'),
            'price_min' => $this->request->getParam('price_min'),
            'price_max' => $this->request->getParam('price_max'),
            'order_by' => $this->request->getParam('order_by'),
            'order_dir' => $this->request->getParam('order_dir'),
        ];
        
        /** @var SearchService $searchService */
        $searchService = ObjectManager::getInstance(SearchService::class);
        
        $result = [];
        if (!empty($keyword)) {
            $result = $searchService->searchProducts($keyword, $filters, $page, $pageSize);
        }
        
        // 获取热门搜索词
        $popularKeywords = $searchService->getPopularKeywords(10);
        
        $this->assign('keyword', $keyword);
        $this->assign('products', $result['items'] ?? []);
        $this->assign('pagination', $result['pagination'] ?? '');
        $this->assign('popularKeywords', $popularKeywords);
        $this->assign('filters', $filters);
        
        return $this->fetch();
    }
}
