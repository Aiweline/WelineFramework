<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\State;

/**
 * 配送方式筛选提供者
 */
class ShippingFilterProvider extends AbstractFilterProvider
{
    public const SHIPPING_FREE = 'free_shipping';
    public const SHIPPING_SAME_DAY = 'same_day';
    public const SHIPPING_NEXT_DAY = 'next_day';
    public const SHIPPING_EXPRESS = 'express';
    
    public function __construct()
    {
        $this->sortOrder = 25;
        $this->displayType = 'checkbox';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'shipping';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $lang = State::getLangLocal();
        $isEnglish = str_starts_with($lang, 'en');
        return $isEnglish ? 'Shipping' : __('配送方式');
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
        
        // 免运费
        $freeShippingCount = $this->countFreeShippingProducts($productIds);
        if ($freeShippingCount > 0) {
            $options[] = $this->buildOption(
                self::SHIPPING_FREE,
                __('免运费'),
                $freeShippingCount,
                $this->isValueSelected(self::SHIPPING_FREE, $appliedFilters)
            );
        }
        
        // 当日达（基于产品属性或库存位置）
        $sameDayCount = $this->countSameDayDeliveryProducts($productIds);
        if ($sameDayCount > 0) {
            $options[] = $this->buildOption(
                self::SHIPPING_SAME_DAY,
                __('当日达'),
                $sameDayCount,
                $this->isValueSelected(self::SHIPPING_SAME_DAY, $appliedFilters)
            );
        }
        
        // 次日达
        $nextDayCount = $this->countNextDayDeliveryProducts($productIds);
        if ($nextDayCount > 0) {
            $options[] = $this->buildOption(
                self::SHIPPING_NEXT_DAY,
                __('次日达'),
                $nextDayCount,
                $this->isValueSelected(self::SHIPPING_NEXT_DAY, $appliedFilters)
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
        
        $filteredIds = [];
        
        foreach ($filterValues as $value) {
            switch ($value) {
                case self::SHIPPING_FREE:
                    $filteredIds = array_merge($filteredIds, $this->getFreeShippingProductIds($productIds));
                    break;
                case self::SHIPPING_SAME_DAY:
                    $filteredIds = array_merge($filteredIds, $this->getSameDayDeliveryProductIds($productIds));
                    break;
                case self::SHIPPING_NEXT_DAY:
                    $filteredIds = array_merge($filteredIds, $this->getNextDayDeliveryProductIds($productIds));
                    break;
                case self::SHIPPING_EXPRESS:
                    $filteredIds = array_merge($filteredIds, $this->getExpressDeliveryProductIds($productIds));
                    break;
            }
        }
        
        // 去重并与原始ID取交集
        $filteredIds = array_unique($filteredIds);
        return array_values(array_intersect($productIds, $filteredIds));
    }
    
    /**
     * 统计免运费产品数量
     */
    private function countFreeShippingProducts(array $productIds): int
    {
        // 基于产品的 free_shipping 属性或价格阈值
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields('COUNT(*) as count')
                ->where(Product::fields_ID, $productIds, 'in');
            
            // 检查是否有 free_shipping 字段
            if (defined(Product::class . '::fields_free_shipping')) {
                $productModel->where(Product::fields_free_shipping, 1);
            } else {
                // 假设价格超过某个阈值免运费
                $productModel->where(Product::fields_price, 99, '>=');
            }
            
            $result = $productModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 统计当日达产品数量
     */
    private function countSameDayDeliveryProducts(array $productIds): int
    {
        // 这里简化实现，实际应该检查库存位置
        return $this->countByStockAvailability($productIds, 10);
    }
    
    /**
     * 统计次日达产品数量
     */
    private function countNextDayDeliveryProducts(array $productIds): int
    {
        return $this->countByStockAvailability($productIds, 1);
    }
    
    /**
     * 按库存可用性统计
     */
    private function countByStockAvailability(array $productIds, int $minStock): int
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields('COUNT(*) as count')
                ->where(Product::fields_ID, $productIds, 'in')
                ->where(Product::fields_stock, $minStock, '>=');
            
            $result = $productModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取免运费产品ID
     */
    private function getFreeShippingProductIds(array $productIds): array
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields(Product::fields_ID)
                ->where(Product::fields_ID, $productIds, 'in')
                ->where(Product::fields_price, 99, '>=');
            
            $results = $productModel->select()->fetchArray();
            return array_column($results, Product::fields_ID);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取当日达产品ID
     */
    private function getSameDayDeliveryProductIds(array $productIds): array
    {
        return $this->getProductsByMinStock($productIds, 10);
    }
    
    /**
     * 获取次日达产品ID
     */
    private function getNextDayDeliveryProductIds(array $productIds): array
    {
        return $this->getProductsByMinStock($productIds, 1);
    }
    
    /**
     * 获取快递产品ID
     */
    private function getExpressDeliveryProductIds(array $productIds): array
    {
        return $this->getProductsByMinStock($productIds, 1);
    }
    
    /**
     * 按最低库存获取产品ID
     */
    private function getProductsByMinStock(array $productIds, int $minStock): array
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->reset()
                ->fields(Product::fields_ID)
                ->where(Product::fields_ID, $productIds, 'in')
                ->where(Product::fields_stock, $minStock, '>=');
            
            $results = $productModel->select()->fetchArray();
            return array_column($results, Product::fields_ID);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        $lang = State::getLangLocal();
        $isEnglish = str_starts_with($lang, 'en');
        
        $labels = [
            self::SHIPPING_FREE => $isEnglish ? 'Free Shipping' : __('免运费'),
            self::SHIPPING_SAME_DAY => $isEnglish ? 'Same Day Delivery' : __('当日达'),
            self::SHIPPING_NEXT_DAY => $isEnglish ? 'Next Day Delivery' : __('次日达'),
            self::SHIPPING_EXPRESS => $isEnglish ? 'Express Delivery' : __('快递'),
        ];
        
        return $labels[$value] ?? $value;
    }
}
