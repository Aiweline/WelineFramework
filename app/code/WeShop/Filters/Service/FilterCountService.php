<?php

declare(strict_types=1);

namespace WeShop\Filters\Service;

use WeShop\Filters\Model\FilterRegistry;
use Weline\Framework\Event\EventsManager;

/**
 * 筛选计数服务
 * 
 * 提供异步获取筛选选项计数的功能
 */
class FilterCountService
{
    /**
     * @var FilterRegistry
     */
    private FilterRegistry $registry;
    
    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;
    
    /**
     * @var FilterCacheService
     */
    private FilterCacheService $cacheService;
    
    public function __construct(
        FilterRegistry $registry,
        EventsManager $eventsManager,
        FilterCacheService $cacheService
    ) {
        $this->registry = $registry;
        $this->eventsManager = $eventsManager;
        $this->cacheService = $cacheService;
    }
    
    /**
     * 获取单个筛选器的计数
     * 
     * @param string $filterCode
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @return array
     */
    public function getCounts(
        string $filterCode,
        int $categoryId,
        array $productIds,
        array $appliedFilters = []
    ): array {
        $filter = $this->registry->get($filterCode);
        if ($filter === null || !$filter->isEnabled($categoryId)) {
            return [];
        }
        
        // 移除当前筛选器的条件以获取正确的计数
        $otherFilters = $appliedFilters;
        unset($otherFilters[$filterCode]);
        
        $counts = $filter->getCounts($categoryId, $productIds, $otherFilters);
        
        // 触发计数收集事件（dispatch 需要变量传递）
        $countsEventData = [
            'category_id' => $categoryId,
            'product_ids' => $productIds,
            'filter_code' => $filterCode,
            'counts' => &$counts,
        ];
        $this->eventsManager->dispatch('WeShop_Filters::filter_counts_collect', $countsEventData);
        
        return $counts;
    }
    
    /**
     * 批量获取多个筛选器的计数
     * 
     * @param array $filterCodes
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @return array filterCode => counts 的映射
     */
    public function getBatchCounts(
        array $filterCodes,
        int $categoryId,
        array $productIds,
        array $appliedFilters = []
    ): array {
        $result = [];
        
        foreach ($filterCodes as $filterCode) {
            $result[$filterCode] = $this->getCounts(
                $filterCode,
                $categoryId,
                $productIds,
                $appliedFilters
            );
        }
        
        return $result;
    }
    
    /**
     * 获取所有可用筛选器的计数
     * 
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @return array
     */
    public function getAllCounts(
        int $categoryId,
        array $productIds,
        array $appliedFilters = []
    ): array {
        $collection = $this->registry->getForCategory($categoryId);
        $result = [];
        
        foreach ($collection->getFilters() as $filter) {
            $result[$filter->getCode()] = $this->getCounts(
                $filter->getCode(),
                $categoryId,
                $productIds,
                $appliedFilters
            );
        }
        
        return $result;
    }
}
