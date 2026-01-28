<?php

declare(strict_types=1);

namespace WeShop\Filters\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Model\CategoryFilterConfig;
use WeShop\Filters\Provider\EavAttributeFilterProvider;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选器收集观察者
 * 
 * 在分类页面加载时收集可用的筛选器
 */
class CollectFiltersObserver implements ObserverInterface
{
    /**
     * @var FilterRegistry
     */
    private FilterRegistry $filterRegistry;
    
    /**
     * @var CategoryFilterConfig
     */
    private CategoryFilterConfig $categoryFilterConfig;
    
    /**
     * @var AttributeFilterService
     */
    private AttributeFilterService $attributeFilterService;
    
    public function __construct(
        FilterRegistry $filterRegistry,
        CategoryFilterConfig $categoryFilterConfig,
        AttributeFilterService $attributeFilterService
    ) {
        $this->filterRegistry = $filterRegistry;
        $this->categoryFilterConfig = $categoryFilterConfig;
        $this->attributeFilterService = $attributeFilterService;
    }
    
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 将 Filters 模块添加到请求模块链，以便加载翻译
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $request->addModule('WeShop_Filters');
        } catch (\Throwable $e) {
            // 静默处理
        }
        
        $data = $event->getData('data');
        
        if (!is_array($data)) {
            return;
        }
        
        $categoryId = $data['category_id'] ?? 0;
        $productIds = $data['product_ids'] ?? [];
        
        if ($categoryId <= 0) {
            return;
        }
        
        // 获取分类的筛选配置
        $filterConfigs = $this->categoryFilterConfig->getEnabledFilters($categoryId);
        
        // 注册配置中的EAV属性筛选器
        foreach ($filterConfigs as $config) {
            $attributeId = $config[CategoryFilterConfig::fields_attribute_id] ?? null;
            
            if ($attributeId) {
                // 获取属性信息并注册筛选器
                $this->registerEavAttributeFilter($config, $productIds);
            }
        }
        
        // 如果没有配置，自动发现并注册EAV属性筛选器
        if (empty($filterConfigs) && !empty($productIds)) {
            $this->autoRegisterEavFilters($productIds);
        }
    }
    
    /**
     * 注册EAV属性筛选器
     */
    private function registerEavAttributeFilter(array $config, array $productIds): void
    {
        $attributeId = $config[CategoryFilterConfig::fields_attribute_id] ?? 0;
        $filterCode = $config[CategoryFilterConfig::fields_filter_code] ?? '';
        $sortOrder = $config[CategoryFilterConfig::fields_sort_order] ?? 100;
        
        if ($attributeId <= 0 || empty($filterCode)) {
            return;
        }
        
        // 从 filterCode 中提取属性代码（格式：attr_color）
        $attributeCode = '';
        if (strpos($filterCode, 'attr_') === 0) {
            $attributeCode = substr($filterCode, 5);
        }
        
        if (empty($attributeCode)) {
            return;
        }
        
        // 创建并注册筛选器
        $provider = EavAttributeFilterProvider::create($attributeCode, '', $sortOrder);
        $provider->setDisplayType($config[CategoryFilterConfig::fields_display_type] ?? 'list');
        $provider->setCollapsed((bool)($config[CategoryFilterConfig::fields_is_collapsed] ?? false));
        
        $this->filterRegistry->register($provider);
    }
    
    /**
     * 自动发现并注册EAV属性筛选器
     */
    private function autoRegisterEavFilters(array $productIds): void
    {
        // 获取产品的可筛选属性
        $filterableData = $this->attributeFilterService->getFilterableAttributes(
            'product',
            $productIds
        );
        
        $sortOrder = 200; // EAV属性筛选器的基础排序
        
        foreach ($filterableData as $attributeCode => $data) {
            // 跳过已经有固定筛选器的属性（如brand）
            if ($this->filterRegistry->has($attributeCode)) {
                continue;
            }
            
            $attributeInfo = $data['attribute'];
            $attributeName = $attributeInfo['name'] ?? $attributeCode;
            
            $provider = EavAttributeFilterProvider::create($attributeCode, $attributeName, $sortOrder++);
            
            // 根据属性特性设置显示类型
            if (!empty($data['options'])) {
                $hasSwatches = false;
                foreach ($data['options'] as $option) {
                    if (!empty($option['swatch_color']) || !empty($option['swatch_image'])) {
                        $hasSwatches = true;
                        break;
                    }
                }
                if ($hasSwatches) {
                    $provider->setDisplayType('swatch');
                }
            }
            
            $this->filterRegistry->register($provider);
        }
    }
}
