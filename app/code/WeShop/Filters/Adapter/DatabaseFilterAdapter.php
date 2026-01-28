<?php

declare(strict_types=1);

namespace WeShop\Filters\Adapter;

use WeShop\Filters\Api\FilterAdapterInterface;
use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Model\FilterResult;
use WeShop\Filters\Service\FilterService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 数据库筛选适配器
 * 
 * 使用数据库进行筛选（默认实现）
 */
class DatabaseFilterAdapter implements FilterAdapterInterface
{
    /**
     * @inheritDoc
     */
    public function filter(
        int $categoryId,
        array $productIds,
        array $filters,
        array $facetFields = []
    ): FilterResultInterface {
        // 数据库适配器使用 FilterService 进行实际筛选
        // 这里主要是为了提供统一的接口
        /** @var FilterService $filterService */
        $filterService = ObjectManager::getInstance(FilterService::class);
        
        return $filterService->getFilterResult($categoryId, $productIds, $filters, false);
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'database';
    }
    
    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return true; // 数据库适配器始终可用
    }
    
    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 10; // 低优先级，作为后备
    }
}
