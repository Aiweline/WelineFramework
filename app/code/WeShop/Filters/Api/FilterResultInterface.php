<?php

declare(strict_types=1);

namespace WeShop\Filters\Api;

/**
 * 筛选结果接口
 */
interface FilterResultInterface
{
    /**
     * 获取筛选后的产品ID列表
     * 
     * @return array
     */
    public function getProductIds(): array;
    
    /**
     * 获取所有筛选组数据
     * 
     * @return array
     */
    public function getFilters(): array;
    
    /**
     * 获取已应用的筛选条件
     * 
     * @return array
     */
    public function getAppliedFilters(): array;
    
    /**
     * 获取筛选后的产品总数
     * 
     * @return int
     */
    public function getTotalCount(): int;
    
    /**
     * 获取原始产品总数（筛选前）
     * 
     * @return int
     */
    public function getOriginalCount(): int;
    
    /**
     * 检查是否有筛选条件被应用
     * 
     * @return bool
     */
    public function hasAppliedFilters(): bool;
    
    /**
     * 获取清除所有筛选的URL
     * 
     * @return string
     */
    public function getClearAllUrl(): string;
    
    /**
     * 转换为数组
     * 
     * @return array
     */
    public function toArray(): array;
}
