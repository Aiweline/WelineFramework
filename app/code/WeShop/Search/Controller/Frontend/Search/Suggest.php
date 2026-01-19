<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Frontend\Search;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Search\Service\SearchService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索建议控制器
 */
class Suggest extends FrontendController
{
    /**
     * 获取搜索建议
     */
    public function index(): string
    {
        $keyword = $this->request->getParam('q') ?? '';
        $limit = (int)($this->request->getParam('limit') ?? 10);
        
        if (empty(trim($keyword))) {
            return $this->fetchJson([
                'success' => true,
                'suggestions' => [],
                'data' => [],
            ]);
        }
        
        /** @var SearchService $searchService */
        $searchService = ObjectManager::getInstance(SearchService::class);
        $suggestions = $searchService->getSearchSuggestions($keyword, $limit);
        
        // 返回格式兼容前端JS期望的格式
        return $this->fetchJson([
            'success' => true,
            'suggestions' => $suggestions,
            'data' => $suggestions, // 兼容字段
        ]);
    }
}
