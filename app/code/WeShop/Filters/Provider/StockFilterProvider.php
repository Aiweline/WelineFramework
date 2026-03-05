<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\State;

/**
 * 库存筛选提供者
 */
class StockFilterProvider extends AbstractFilterProvider
{
    public const STOCK_IN = 'in_stock';
    public const STOCK_OUT = 'out_of_stock';
    public const STOCK_LOW = 'low_stock';
    
    /**
     * @var int 低库存阈值
     */
    private int $lowStockThreshold = 10;
    
    public function __construct()
    {
        $this->sortOrder = 30;
        $this->displayType = 'checkbox';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'stock';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('库存状态');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $stockCounts = $this->getStockCounts($productIds);
        $options = [];
        
        // 有货
        if ($stockCounts[self::STOCK_IN] > 0) {
            $options[] = $this->buildOption(
                self::STOCK_IN,
                __('有货'),
                $stockCounts[self::STOCK_IN],
                $this->isValueSelected(self::STOCK_IN, $appliedFilters)
            );
        }
        
        // 缺货（可选显示）
        if ($stockCounts[self::STOCK_OUT] > 0) {
            $options[] = $this->buildOption(
                self::STOCK_OUT,
                __('缺货'),
                $stockCounts[self::STOCK_OUT],
                $this->isValueSelected(self::STOCK_OUT, $appliedFilters)
            );
        }
        
        return $options;
    }
    
    /**
     * @inheritDoc
     */
    public function apply(array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues)) {
            return $productIds;
        }
        
        $conditions = [];
        foreach ($filterValues as $value) {
            switch ($value) {
                case self::STOCK_IN:
                    $conditions[] = Product::schema_fields_stock . ' > 0';
                    break;
                case self::STOCK_OUT:
                    $conditions[] = Product::schema_fields_stock . ' <= 0';
                    break;
                case self::STOCK_LOW:
                    $conditions[] = sprintf(
                        '%s > 0 AND %s <= %d',
                        Product::schema_fields_stock,
                        Product::schema_fields_stock,
                        $this->lowStockThreshold
                    );
                    break;
            }
        }
        
        if (empty($conditions)) {
            return $productIds;
        }
        
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->reset()
            ->fields(Product::schema_fields_ID)
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where('(' . implode(' OR ', $conditions) . ')');
        
        $results = $productModel->select()->fetchArray();
        
        return array_column($results, Product::schema_fields_ID);
    }
    
    /**
     * 获取库存状态统计
     */
    private function getStockCounts(array $productIds): array
    {
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        
        // 有货数量
        $productModel->reset()
            ->fields('COUNT(*) as count')
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where(Product::schema_fields_stock, 0, '>');
        $inStock = (int)($productModel->find()->fetchArray()['count'] ?? 0);
        
        // 缺货数量
        $productModel->reset()
            ->fields('COUNT(*) as count')
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where(Product::schema_fields_stock, 0, '<=');
        $outOfStock = (int)($productModel->find()->fetchArray()['count'] ?? 0);
        
        return [
            self::STOCK_IN => $inStock,
            self::STOCK_OUT => $outOfStock,
        ];
    }
    
    /**
     * 设置低库存阈值
     */
    public function setLowStockThreshold(int $threshold): self
    {
        $this->lowStockThreshold = $threshold;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        $labels = [
            self::STOCK_IN => __('有货'),
            self::STOCK_OUT => __('缺货'),
            self::STOCK_LOW => __('库存紧张'),
        ];
        
        return $labels[$value] ?? $value;
    }
}
