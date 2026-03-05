<?php

declare(strict_types=1);

namespace WeShop\Filters\Controller\Backend;

use Weline\Framework\Controller\PcController;
use WeShop\Filters\Model\CategoryFilterConfig;
use WeShop\Filters\Model\PriceRange;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Service\FilterCacheService;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\MessageManager;

/**
 * 后台筛选配置控制器
 */
class Config extends PcController
{
    /**
     * @var CategoryFilterConfig
     */
    private CategoryFilterConfig $categoryFilterConfig;
    
    /**
     * @var PriceRange
     */
    private PriceRange $priceRange;
    
    /**
     * @var FilterRegistry
     */
    private FilterRegistry $filterRegistry;
    
    /**
     * @var FilterCacheService
     */
    private FilterCacheService $cacheService;
    
    public function __construct(
        CategoryFilterConfig $categoryFilterConfig,
        PriceRange $priceRange,
        FilterRegistry $filterRegistry,
        FilterCacheService $cacheService
    ) {
        $this->categoryFilterConfig = $categoryFilterConfig;
        $this->priceRange = $priceRange;
        $this->filterRegistry = $filterRegistry;
        $this->cacheService = $cacheService;
    }
    
    /**
     * 筛选配置首页
     */
    public function index(): string
    {
        // 获取全局筛选配置
        $globalConfigs = $this->categoryFilterConfig->getEnabledFilters(0, false);
        
        // 获取所有可用的筛选器
        $availableFilters = $this->filterRegistry->getAll();
        
        // 获取产品的可筛选EAV属性
        /** @var AttributeFilterService $attributeFilterService */
        $attributeFilterService = ObjectManager::getInstance(AttributeFilterService::class);
        $eavAttributes = $attributeFilterService->getFilterableAttributesByGroup('product', []);
        
        $this->assign('global_configs', $globalConfigs);
        $this->assign('available_filters', $availableFilters);
        $this->assign('eav_attributes', $eavAttributes);
        
        return $this->fetch();
    }
    
    /**
     * 保存筛选配置
     */
    public function save(): string
    {
        $categoryId = (int)$this->request->getPost('category_id', 0);
        $filters = $this->request->getPost('filters', []);
        
        if (!is_array($filters)) {
            MessageManager::error(__('无效的筛选配置'));
            return $this->redirect('*/*/index');
        }
        
        try {
            // 删除现有配置
            $this->categoryFilterConfig->deleteFilterConfig($categoryId);
            
            // 保存新配置
            foreach ($filters as $filterCode => $config) {
                if (!isset($config['enabled']) || !$config['enabled']) {
                    continue;
                }
                
                $configData = [
                    CategoryFilterConfig::schema_fields_sort_order => (int)($config['sort_order'] ?? 100),
                    CategoryFilterConfig::schema_fields_is_enabled => 1,
                    CategoryFilterConfig::schema_fields_display_type => $config['display_type'] ?? 'list',
                    CategoryFilterConfig::schema_fields_is_collapsed => (int)($config['collapsed'] ?? 0),
                    CategoryFilterConfig::schema_fields_inherit_parent => (int)($config['inherit_parent'] ?? 1),
                    CategoryFilterConfig::schema_fields_config_data => json_encode($config['extra'] ?? []),
                ];
                
                // 如果是EAV属性筛选
                if (isset($config['attribute_id'])) {
                    $configData[CategoryFilterConfig::schema_fields_attribute_id] = (int)$config['attribute_id'];
                }
                
                $this->categoryFilterConfig->saveFilterConfig($categoryId, $filterCode, $configData);
            }
            
            // 清除缓存
            if ($categoryId > 0) {
                $this->cacheService->clearByCategoryId($categoryId);
            } else {
                $this->cacheService->clearAll();
            }
            
            MessageManager::success(__('筛选配置已保存'));
        } catch (\Throwable $e) {
            MessageManager::error(__('保存失败: %1', $e->getMessage()));
        }
        
        return $this->redirect('*/*/index');
    }
    
    /**
     * 价格区间配置页面
     */
    public function priceRanges(): string
    {
        $categoryId = (int)$this->request->getGet('category_id', 0);
        
        $ranges = $this->priceRange->getPriceRanges($categoryId);
        
        $this->assign('category_id', $categoryId);
        $this->assign('price_ranges', $ranges);
        
        return $this->fetch();
    }
    
    /**
     * 保存价格区间
     */
    public function savePriceRanges(): string
    {
        $categoryId = (int)$this->request->getPost('category_id', 0);
        $ranges = $this->request->getPost('ranges', []);
        
        if (!is_array($ranges)) {
            MessageManager::error(__('无效的价格区间配置'));
            return $this->redirect('*/*/priceRanges', ['category_id' => $categoryId]);
        }
        
        try {
            $this->priceRange->savePriceRanges($categoryId, $ranges);
            
            // 清除缓存
            if ($categoryId > 0) {
                $this->cacheService->clearByCategoryId($categoryId);
            } else {
                $this->cacheService->clearAll();
            }
            
            MessageManager::success(__('价格区间已保存'));
        } catch (\Throwable $e) {
            MessageManager::error(__('保存失败: %1', $e->getMessage()));
        }
        
        return $this->redirect('*/*/priceRanges', ['category_id' => $categoryId]);
    }
    
    /**
     * 清除筛选缓存
     */
    public function clearCache(): string
    {
        $categoryId = (int)$this->request->getGet('category_id', 0);
        
        try {
            if ($categoryId > 0) {
                $this->cacheService->clearByCategoryId($categoryId);
                MessageManager::success(__('分类筛选缓存已清除'));
            } else {
                $this->cacheService->clearAll();
                MessageManager::success(__('所有筛选缓存已清除'));
            }
        } catch (\Throwable $e) {
            MessageManager::error(__('清除缓存失败: %1', $e->getMessage()));
        }
        
        return $this->redirect('*/*/index');
    }
}
