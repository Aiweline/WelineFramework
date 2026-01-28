<?php

declare(strict_types=1);

namespace WeShop\Filters\Api;

/**
 * 筛选提供者接口
 * 
 * 所有筛选器必须实现此接口
 * 其他模块可以通过实现此接口添加自定义筛选
 */
interface FilterProviderInterface
{
    /**
     * 获取筛选器代码
     * 
     * @return string 唯一标识符，如 'price', 'brand', 'color'
     */
    public function getCode(): string;
    
    /**
     * 获取筛选器名称（用于显示）
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * 获取筛选选项
     * 
     * @param int $categoryId 分类ID
     * @param array $productIds 当前分类下的产品ID列表
     * @param array $appliedFilters 当前已应用的筛选条件
     * @return array 筛选选项列表
     * [
     *     [
     *         'value' => 'option_value',
     *         'label' => '显示标签',
     *         'count' => 10, // 产品数量（可选）
     *         'selected' => false, // 是否已选中
     *         'swatch' => [ // 样本数据（可选，用于颜色等）
     *             'type' => 'color|image|text',
     *             'value' => '#ff0000' | 'image_url' | 'text'
     *         ]
     *     ],
     *     ...
     * ]
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array;
    
    /**
     * 应用筛选条件
     * 
     * @param array $productIds 待筛选的产品ID列表
     * @param array $filterValues 筛选值，如 ['red', 'blue'] 或 ['100-500']
     * @return array 筛选后的产品ID列表
     */
    public function apply(array $productIds, array $filterValues): array;
    
    /**
     * 获取选项计数
     * 
     * @param int $categoryId 分类ID
     * @param array $productIds 当前产品ID列表
     * @param array $appliedFilters 当前已应用的筛选条件（不包含本筛选器）
     * @return array 选项值 => 计数 的映射
     */
    public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array;
    
    /**
     * 获取排序权重
     * 
     * @return int 数字越小越靠前
     */
    public function getSortOrder(): int;
    
    /**
     * 检查筛选器是否在指定分类启用
     * 
     * @param int $categoryId 分类ID
     * @return bool
     */
    public function isEnabled(int $categoryId): bool;
    
    /**
     * 获取筛选器显示类型
     * 
     * @return string 'list'|'swatch'|'slider'|'checkbox'|'radio'
     */
    public function getDisplayType(): string;
    
    /**
     * 是否默认折叠
     * 
     * @return bool
     */
    public function isCollapsed(): bool;
    
    /**
     * 获取筛选器图标（可选）
     * 
     * @return string|null
     */
    public function getIcon(): ?string;
}
