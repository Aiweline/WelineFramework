<?php

declare(strict_types=1);

namespace WeShop\Filters\Api;

/**
 * 筛选适配器接口
 * 
 * 用于支持不同的筛选后端（数据库、Manticore Search等）
 */
interface FilterAdapterInterface
{
    /**
     * 执行筛选并返回结果
     * 
     * @param int $categoryId 分类ID
     * @param array $productIds 初始产品ID列表
     * @param array $filters 筛选条件
     * @param array $facetFields 需要聚合的字段
     * @return FilterResultInterface
     */
    public function filter(
        int $categoryId,
        array $productIds,
        array $filters,
        array $facetFields = []
    ): FilterResultInterface;
    
    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * 检查适配器是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool;
    
    /**
     * 获取适配器优先级
     * 
     * @return int 数字越大优先级越高
     */
    public function getPriority(): int;
}
