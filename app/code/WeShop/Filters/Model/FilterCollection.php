<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use WeShop\Filters\Api\FilterCollectionInterface;
use WeShop\Filters\Api\FilterProviderInterface;

/**
 * 筛选器集合
 */
class FilterCollection implements FilterCollectionInterface
{
    /**
     * @var FilterProviderInterface[]
     */
    private array $filters = [];
    
    /**
     * @var bool 是否已排序
     */
    private bool $sorted = false;
    
    /**
     * @inheritDoc
     */
    public function addFilter(FilterProviderInterface $filter): FilterCollectionInterface
    {
        $this->filters[$filter->getCode()] = $filter;
        $this->sorted = false;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function removeFilter(string $code): FilterCollectionInterface
    {
        unset($this->filters[$code]);
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getFilter(string $code): ?FilterProviderInterface
    {
        return $this->filters[$code] ?? null;
    }
    
    /**
     * @inheritDoc
     */
    public function hasFilter(string $code): bool
    {
        return isset($this->filters[$code]);
    }
    
    /**
     * @inheritDoc
     */
    public function getFilters(): array
    {
        if (!$this->sorted) {
            $this->sortFilters();
        }
        return array_values($this->filters);
    }
    
    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return count($this->filters);
    }
    
    /**
     * @inheritDoc
     */
    public function clear(): FilterCollectionInterface
    {
        $this->filters = [];
        $this->sorted = false;
        return $this;
    }
    
    /**
     * 按排序权重排序筛选器
     */
    private function sortFilters(): void
    {
        uasort($this->filters, function (FilterProviderInterface $a, FilterProviderInterface $b) {
            return $a->getSortOrder() <=> $b->getSortOrder();
        });
        $this->sorted = true;
    }
}
