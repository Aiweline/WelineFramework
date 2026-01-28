<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use WeShop\Filters\Api\FilterResultInterface;

/**
 * 筛选结果模型
 */
class FilterResult implements FilterResultInterface
{
    /**
     * @var array 筛选后的产品ID
     */
    private array $productIds = [];
    
    /**
     * @var array 所有筛选组数据
     */
    private array $filters = [];
    
    /**
     * @var array 已应用的筛选条件
     */
    private array $appliedFilters = [];
    
    /**
     * @var int 原始产品总数
     */
    private int $originalCount = 0;
    
    /**
     * @var string 清除所有筛选的URL
     */
    private string $clearAllUrl = '';
    
    /**
     * 设置筛选后的产品ID
     * 
     * @param array $productIds
     * @return self
     */
    public function setProductIds(array $productIds): self
    {
        $this->productIds = $productIds;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }
    
    /**
     * 设置筛选组数据
     * 
     * @param array $filters
     * @return self
     */
    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getFilters(): array
    {
        return $this->filters;
    }
    
    /**
     * 设置已应用的筛选条件
     * 
     * @param array $appliedFilters
     * @return self
     */
    public function setAppliedFilters(array $appliedFilters): self
    {
        $this->appliedFilters = $appliedFilters;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getAppliedFilters(): array
    {
        return $this->appliedFilters;
    }
    
    /**
     * @inheritDoc
     */
    public function getTotalCount(): int
    {
        return count($this->productIds);
    }
    
    /**
     * 设置原始产品总数
     * 
     * @param int $count
     * @return self
     */
    public function setOriginalCount(int $count): self
    {
        $this->originalCount = $count;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getOriginalCount(): int
    {
        return $this->originalCount;
    }
    
    /**
     * @inheritDoc
     */
    public function hasAppliedFilters(): bool
    {
        return !empty($this->appliedFilters);
    }
    
    /**
     * 设置清除所有筛选的URL
     * 
     * @param string $url
     * @return self
     */
    public function setClearAllUrl(string $url): self
    {
        $this->clearAllUrl = $url;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getClearAllUrl(): string
    {
        return $this->clearAllUrl;
    }
    
    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'product_ids' => $this->productIds,
            'filters' => $this->filters,
            'applied_filters' => $this->appliedFilters,
            'total_count' => $this->getTotalCount(),
            'original_count' => $this->originalCount,
            'has_applied_filters' => $this->hasAppliedFilters(),
            'clear_all_url' => $this->clearAllUrl,
        ];
    }
}
