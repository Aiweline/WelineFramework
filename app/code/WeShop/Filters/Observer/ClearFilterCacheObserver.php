<?php

declare(strict_types=1);

namespace WeShop\Filters\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Filters\Service\FilterCacheService;
use WeShop\Product\Model\ProductCategory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

/**
 * 清除筛选缓存观察者
 * 
 * 在产品或分类保存后清除相关的筛选缓存
 */
class ClearFilterCacheObserver implements ObserverInterface
{
    /**
     * @var FilterCacheService
     */
    private FilterCacheService $cacheService;
    
    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;
    
    public function __construct(
        FilterCacheService $cacheService,
        EventsManager $eventsManager
    ) {
        $this->cacheService = $cacheService;
        $this->eventsManager = $eventsManager;
    }
    
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        $data = $event->getData('data');
        
        $categoryIds = [];
        
        if (strpos($eventName, 'product_save_after') !== false) {
            // 产品保存后，清除产品所属分类的缓存
            $categoryIds = $this->getProductCategoryIds($data);
        } elseif (strpos($eventName, 'category_save_after') !== false) {
            // 分类保存后，清除该分类的缓存
            $categoryId = $this->getCategoryIdFromData($data);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }
        
        if (empty($categoryIds)) {
            return;
        }
        
        // 清除缓存
        foreach ($categoryIds as $categoryId) {
            $this->cacheService->clearByCategoryId($categoryId);
        }
        
        // 触发缓存清除事件
        $this->eventsManager->dispatch('WeShop_Filters::cache_clear', [
            'category_ids' => $categoryIds,
            'clear_all' => false,
        ]);
    }
    
    /**
     * 从产品数据中获取分类ID列表
     */
    private function getProductCategoryIds($data): array
    {
        $productId = 0;
        
        if (is_array($data)) {
            $productId = (int)($data['product_id'] ?? $data['id'] ?? 0);
        } elseif (is_object($data) && method_exists($data, 'getId')) {
            $productId = (int)$data->getId();
        }
        
        if ($productId <= 0) {
            return [];
        }
        
        try {
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            return $productCategory->getCategoryIdsByProductId($productId);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 从分类数据中获取分类ID
     */
    private function getCategoryIdFromData($data): int
    {
        if (is_array($data)) {
            return (int)($data['category_id'] ?? $data['id'] ?? 0);
        } elseif (is_object($data) && method_exists($data, 'getId')) {
            return (int)$data->getId();
        }
        
        return 0;
    }
}
