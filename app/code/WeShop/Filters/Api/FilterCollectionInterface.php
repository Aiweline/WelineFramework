<?php

declare(strict_types=1);

namespace WeShop\Filters\Api;

/**
 * 筛选集合接口
 */
interface FilterCollectionInterface
{
    /**
     * 添加筛选器
     * 
     * @param FilterProviderInterface $filter
     * @return self
     */
    public function addFilter(FilterProviderInterface $filter): self;
    
    /**
     * 移除筛选器
     * 
     * @param string $code 筛选器代码
     * @return self
     */
    public function removeFilter(string $code): self;
    
    /**
     * 获取筛选器
     * 
     * @param string $code
     * @return FilterProviderInterface|null
     */
    public function getFilter(string $code): ?FilterProviderInterface;
    
    /**
     * 检查筛选器是否存在
     * 
     * @param string $code
     * @return bool
     */
    public function hasFilter(string $code): bool;
    
    /**
     * 获取所有筛选器（已排序）
     * 
     * @return FilterProviderInterface[]
     */
    public function getFilters(): array;
    
    /**
     * 获取筛选器数量
     * 
     * @return int
     */
    public function count(): int;
    
    /**
     * 清空所有筛选器
     * 
     * @return self
     */
    public function clear(): self;
}
