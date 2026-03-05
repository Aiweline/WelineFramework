<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

/**
 * 新品筛选提供者
 */
class NewFilterProvider extends AbstractFilterProvider
{
    public const NEW_PRODUCTS = 'new';
    
    /**
     * @var int 新品天数阈值（最近N天内添加的视为新品）
     */
    private int $newDays = 30;
    
    public function __construct()
    {
        $this->sortOrder = 40;
        $this->displayType = 'checkbox';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'new';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('新品');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $newCount = $this->countNewProducts($productIds);
        
        if ($newCount === 0) {
            return [];
        }
        
        return [
            $this->buildOption(
                self::NEW_PRODUCTS,
                __('新品上市'),
                $newCount,
                $this->isValueSelected(self::NEW_PRODUCTS, $appliedFilters)
            ),
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function apply(array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues)) {
            return $productIds;
        }
        
        if (!in_array(self::NEW_PRODUCTS, $filterValues, true)) {
            return $productIds;
        }
        
        return $this->getNewProductIds($productIds);
    }
    
    /**
     * 统计新品数量
     */
    private function countNewProducts(array $productIds): int
    {
        $newDate = date('Y-m-d H:i:s', strtotime("-{$this->newDays} days"));
        
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields('COUNT(*) as count')
                ->where(Product::schema_fields_ID, $productIds, 'in')
                ->where('created_at', $newDate, '>=');
            
            // 检查是否有 is_new 字段
            if (defined(Product::class . '::schema_fields_is_new')) {
                $productModel->where(Product::schema_fields_is_new, 1, '=', 'OR');
            }
            
            $result = $productModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取新品ID列表
     */
    private function getNewProductIds(array $productIds): array
    {
        $newDate = date('Y-m-d H:i:s', strtotime("-{$this->newDays} days"));
        
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields(Product::schema_fields_ID)
                ->where(Product::schema_fields_ID, $productIds, 'in')
                ->where('created_at', $newDate, '>=');
            
            $results = $productModel->select()->fetchArray();
            return array_column($results, Product::schema_fields_ID);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 设置新品天数阈值
     */
    public function setNewDays(int $days): self
    {
        $this->newDays = $days;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        if ($value === self::NEW_PRODUCTS) {
            return __('新品上市');
        }
        return $value;
    }
}
