<?php

declare(strict_types=1);

namespace WeShop\Filters\Service;

use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Api\FilterCollectionInterface;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Model\FilterResult;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选服务
 * 
 * 提供筛选功能的核心入口
 */
class FilterService
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
    
    /**
     * @var FilterUrlService
     */
    private FilterUrlService $urlService;
    
    public function __construct(
        FilterRegistry $registry,
        EventsManager $eventsManager,
        FilterCacheService $cacheService,
        FilterUrlService $urlService
    ) {
        $this->registry = $registry;
        $this->eventsManager = $eventsManager;
        $this->cacheService = $cacheService;
        $this->urlService = $urlService;
    }
    
    /**
     * 获取筛选结果
     * 
     * @param int $categoryId 分类ID
     * @param array $productIds 原始产品ID列表
     * @param array $filterParams URL中的筛选参数
     * @param bool $useCache 是否使用缓存
     * @return FilterResultInterface
     */
    public function getFilterResult(
        int $categoryId,
        array $productIds,
        array $filterParams = [],
        bool $useCache = true
    ): FilterResultInterface {
        // 尝试从缓存获取
        if ($useCache) {
            $cacheKey = $this->cacheService->generateCacheKey($categoryId, $filterParams);
            $cached = $this->cacheService->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // 获取可用的筛选器集合
        $filterCollection = $this->collectFilters($categoryId, $productIds);
        
        // 触发筛选前事件（dispatch 需要变量传递，不能直接传数组字面量）
        $beforeEventData = [
            'category_id' => $categoryId,
            'product_ids' => $productIds,
            'filter_params' => &$filterParams,
        ];
        $this->eventsManager->dispatch('WeShop_Filters::filters_apply_before', $beforeEventData);
        
        // 应用筛选条件
        $filteredProductIds = $this->applyFilters($productIds, $filterParams, $filterCollection);
        
        // 触发筛选后事件
        $afterEventData = [
            'category_id' => $categoryId,
            'original_product_ids' => $productIds,
            'filtered_product_ids' => &$filteredProductIds,
            'filter_params' => $filterParams,
        ];
        $this->eventsManager->dispatch('WeShop_Filters::filters_apply_after', $afterEventData);
        
        // 构建筛选组数据（使用筛选后的产品ID更新计数）
        $filtersData = $this->buildFiltersData($categoryId, $filteredProductIds, $filterParams, $filterCollection);
        
        // 构建已应用筛选数据
        $appliedFilters = $this->buildAppliedFilters($filterParams, $filterCollection);
        
        // 创建结果对象
        /** @var FilterResult $result */
        $result = ObjectManager::getInstance(FilterResult::class);
        $result->setProductIds($filteredProductIds)
            ->setFilters($filtersData)
            ->setAppliedFilters($appliedFilters)
            ->setOriginalCount(count($productIds))
            ->setClearAllUrl($this->urlService->getClearAllUrl($categoryId));
        
        // 缓存结果
        if ($useCache) {
            $this->cacheService->set($cacheKey, $result);
        }
        
        return $result;
    }
    
    /**
     * 收集可用的筛选器
     * 
     * @param int $categoryId
     * @param array $productIds
     * @return FilterCollectionInterface
     */
    public function collectFilters(int $categoryId, array $productIds): FilterCollectionInterface
    {
        $collection = $this->registry->getForCategory($categoryId);
        
        // 触发筛选器收集事件，允许其他模块添加自定义筛选器
        $eventData = [
            'category_id' => $categoryId,
            'product_ids' => $productIds,
            'filters' => $collection,
        ];
        $this->eventsManager->dispatch('WeShop_Filters::filters_collect', $eventData);
        
        return $collection;
    }
    
    /**
     * 应用筛选条件
     * 
     * @param array $productIds
     * @param array $filterParams
     * @param FilterCollectionInterface $collection
     * @return array 筛选后的产品ID
     */
    private function applyFilters(
        array $productIds,
        array $filterParams,
        FilterCollectionInterface $collection
    ): array {
        if (empty($filterParams)) {
            return $productIds;
        }
        
        $filteredIds = $productIds;
        
        foreach ($filterParams as $filterCode => $filterValues) {
            $filter = $collection->getFilter($filterCode);
            if ($filter === null) {
                continue;
            }
            
            // 确保 filterValues 是数组
            if (!is_array($filterValues)) {
                $filterValues = [$filterValues];
            }
            
            // 应用筛选
            $filteredIds = $filter->apply($filteredIds, $filterValues);
            
            // 如果没有结果了，提前返回
            if (empty($filteredIds)) {
                break;
            }
        }
        
        return $filteredIds;
    }
    
    /**
     * 构建筛选组数据
     * 
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @param FilterCollectionInterface $collection
     * @return array
     */
    private function buildFiltersData(
        int $categoryId,
        array $productIds,
        array $appliedFilters,
        FilterCollectionInterface $collection
    ): array {
        $filtersData = [];
        
        foreach ($collection->getFilters() as $filter) {
            $filterCode = $filter->getCode();
            
            // 获取不包含当前筛选器的已应用筛选
            $otherFilters = $appliedFilters;
            unset($otherFilters[$filterCode]);
            
            // 获取筛选选项
            $options = $filter->getOptions($categoryId, $productIds, $appliedFilters);
            
            // 触发选项收集事件（dispatch 需要变量传递）
            $optionsEventData = [
                'filter_code' => $filterCode,
                'category_id' => $categoryId,
                'product_ids' => $productIds,
                'options' => &$options,
            ];
            $this->eventsManager->dispatch('WeShop_Filters::filter_options_collect', $optionsEventData);
            
            // 如果没有选项，跳过
            if (empty($options)) {
                continue;
            }
            
            $filtersData[] = [
                'code' => $filterCode,
                'name' => $filter->getName(),
                'display_type' => $filter->getDisplayType(),
                'collapsed' => $filter->isCollapsed(),
                'icon' => $filter->getIcon(),
                'options' => $options,
            ];
        }
        
        return $filtersData;
    }
    
    /**
     * 构建已应用筛选数据
     * 
     * @param array $filterParams
     * @param FilterCollectionInterface $collection
     * @return array
     */
    private function buildAppliedFilters(
        array $filterParams,
        FilterCollectionInterface $collection
    ): array {
        $appliedFilters = [];
        
        foreach ($filterParams as $filterCode => $filterValues) {
            $filter = $collection->getFilter($filterCode);
            if ($filter === null) {
                continue;
            }
            
            if (!is_array($filterValues)) {
                $filterValues = [$filterValues];
            }
            
            foreach ($filterValues as $value) {
                $appliedFilters[] = [
                    'filter_code' => $filterCode,
                    'filter_name' => $filter->getName(),
                    'value' => $value,
                    'label' => $this->getValueLabel($filter, $value),
                    'remove_url' => $this->urlService->getRemoveFilterUrl($filterCode, $value),
                ];
            }
        }
        
        return $appliedFilters;
    }
    
    /**
     * 获取筛选值的显示标签
     * 
     * @param FilterProviderInterface $filter
     * @param string $value
     * @return string
     */
    private function getValueLabel(FilterProviderInterface $filter, string $value): string
    {
        // 调用筛选器的 getValueLabel 方法获取翻译后的标签
        return $filter->getValueLabel($value);
    }
    
    /**
     * 获取单个筛选器的选项
     * 
     * @param string $filterCode
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @return array
     */
    public function getFilterOptions(
        string $filterCode,
        int $categoryId,
        array $productIds,
        array $appliedFilters = []
    ): array {
        $filter = $this->registry->get($filterCode);
        if ($filter === null || !$filter->isEnabled($categoryId)) {
            return [];
        }
        
        return $filter->getOptions($categoryId, $productIds, $appliedFilters);
    }
    
    /**
     * 获取筛选器计数
     * 
     * @param string $filterCode
     * @param int $categoryId
     * @param array $productIds
     * @param array $appliedFilters
     * @return array
     */
    public function getFilterCounts(
        string $filterCode,
        int $categoryId,
        array $productIds,
        array $appliedFilters = []
    ): array {
        $filter = $this->registry->get($filterCode);
        if ($filter === null || !$filter->isEnabled($categoryId)) {
            return [];
        }
        
        return $filter->getCounts($categoryId, $productIds, $appliedFilters);
    }
    
    /**
     * 获取筛选注册表
     * 
     * @return FilterRegistry
     */
    public function getRegistry(): FilterRegistry
    {
        return $this->registry;
    }
}
