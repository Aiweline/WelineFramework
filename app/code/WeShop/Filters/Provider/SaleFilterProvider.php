<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

/**
 * 促销/折扣筛选提供者
 */
class SaleFilterProvider extends AbstractFilterProvider
{
    public const ON_SALE = 'on_sale';
    public const DISCOUNT_10 = 'discount_10';
    public const DISCOUNT_20 = 'discount_20';
    public const DISCOUNT_30 = 'discount_30';
    public const DISCOUNT_50 = 'discount_50';
    
    public function __construct()
    {
        $this->sortOrder = 45;
        $this->displayType = 'checkbox';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'sale';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('促销');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $options = [];
        
        // 所有促销商品
        $saleCount = $this->countSaleProducts($productIds);
        if ($saleCount > 0) {
            $options[] = $this->buildOption(
                self::ON_SALE,
                __('促销商品'),
                $saleCount,
                $this->isValueSelected(self::ON_SALE, $appliedFilters)
            );
        }
        
        // 按折扣力度分组
        $discountOptions = [
            self::DISCOUNT_10 => ['min' => 10, 'label' => __('9折及以上')],
            self::DISCOUNT_20 => ['min' => 20, 'label' => __('8折及以上')],
            self::DISCOUNT_30 => ['min' => 30, 'label' => __('7折及以上')],
            self::DISCOUNT_50 => ['min' => 50, 'label' => __('5折及以上')],
        ];
        
        foreach ($discountOptions as $value => $config) {
            $count = $this->countByDiscount($productIds, $config['min']);
            if ($count > 0) {
                $options[] = $this->buildOption(
                    $value,
                    $config['label'],
                    $count,
                    $this->isValueSelected($value, $appliedFilters)
                );
            }
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
        
        $filteredIds = [];
        
        foreach ($filterValues as $value) {
            switch ($value) {
                case self::ON_SALE:
                    $filteredIds = array_merge($filteredIds, $this->getSaleProductIds($productIds));
                    break;
                case self::DISCOUNT_10:
                    $filteredIds = array_merge($filteredIds, $this->getProductsByDiscount($productIds, 10));
                    break;
                case self::DISCOUNT_20:
                    $filteredIds = array_merge($filteredIds, $this->getProductsByDiscount($productIds, 20));
                    break;
                case self::DISCOUNT_30:
                    $filteredIds = array_merge($filteredIds, $this->getProductsByDiscount($productIds, 30));
                    break;
                case self::DISCOUNT_50:
                    $filteredIds = array_merge($filteredIds, $this->getProductsByDiscount($productIds, 50));
                    break;
            }
        }
        
        return array_values(array_unique($filteredIds));
    }
    
    /**
     * 统计促销商品数量
     */
    private function countSaleProducts(array $productIds): int
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields('COUNT(*) as count')
                ->where(Product::fields_ID, $productIds, 'in');
            
            // 检查是否有 special_price 或 sale_price 字段
            if (defined(Product::class . '::fields_special_price')) {
                $productModel->where(Product::fields_special_price, 0, '>')
                    ->where(Product::fields_special_price, Product::fields_price, '<', 'AND', true);
            } elseif (defined(Product::class . '::fields_sale_price')) {
                $productModel->where(Product::fields_sale_price, 0, '>')
                    ->where(Product::fields_sale_price, Product::fields_price, '<', 'AND', true);
            } else {
                // 没有促销价格字段，返回0
                return 0;
            }
            
            $result = $productModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 按折扣统计产品数量
     */
    private function countByDiscount(array $productIds, int $minDiscount): int
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            
            // 计算折扣率的SQL条件
            $discountCondition = $this->buildDiscountCondition($minDiscount);
            if (!$discountCondition) {
                return 0;
            }
            
            $productModel->reset()
                ->fields('COUNT(*) as count')
                ->where(Product::fields_ID, $productIds, 'in')
                ->where($discountCondition);
            
            $result = $productModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取促销商品ID
     */
    private function getSaleProductIds(array $productIds): array
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields(Product::fields_ID)
                ->where(Product::fields_ID, $productIds, 'in');
            
            if (defined(Product::class . '::fields_special_price')) {
                $productModel->where(Product::fields_special_price, 0, '>')
                    ->where(Product::fields_special_price, Product::fields_price, '<', 'AND', true);
            } else {
                return [];
            }
            
            $results = $productModel->select()->fetchArray();
            return array_column($results, Product::fields_ID);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 按折扣获取产品ID
     */
    private function getProductsByDiscount(array $productIds, int $minDiscount): array
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            
            $discountCondition = $this->buildDiscountCondition($minDiscount);
            if (!$discountCondition) {
                return [];
            }
            
            $productModel->reset()
                ->fields(Product::fields_ID)
                ->where(Product::fields_ID, $productIds, 'in')
                ->where($discountCondition);
            
            $results = $productModel->select()->fetchArray();
            return array_column($results, Product::fields_ID);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 构建折扣条件SQL
     */
    private function buildDiscountCondition(int $minDiscount): ?string
    {
        $specialPriceField = null;
        
        if (defined(Product::class . '::fields_special_price')) {
            $specialPriceField = Product::fields_special_price;
        } elseif (defined(Product::class . '::fields_sale_price')) {
            $specialPriceField = Product::fields_sale_price;
        }
        
        if (!$specialPriceField) {
            return null;
        }
        
        // 折扣率 = (原价 - 促销价) / 原价 * 100
        // 条件：折扣率 >= minDiscount
        return sprintf(
            '(%s > 0 AND %s > 0 AND ((%s - %s) / %s * 100) >= %d)',
            $specialPriceField,
            Product::fields_price,
            Product::fields_price,
            $specialPriceField,
            Product::fields_price,
            $minDiscount
        );
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        $labels = [
            self::ON_SALE => __('促销商品'),
            self::DISCOUNT_10 => __('9折及以上'),
            self::DISCOUNT_20 => __('8折及以上'),
            self::DISCOUNT_30 => __('7折及以上'),
            self::DISCOUNT_50 => __('5折及以上'),
        ];
        
        return $labels[$value] ?? $value;
    }
}
