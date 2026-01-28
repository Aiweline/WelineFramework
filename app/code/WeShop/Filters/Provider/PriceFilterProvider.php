<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Filters\Model\PriceRange;
use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

/**
 * 价格筛选提供者
 * 
 * 支持三种模式：
 * 1. preset - 预设区间
 * 2. dynamic - 动态计算区间
 * 3. slider - 滑块选择器
 */
class PriceFilterProvider extends AbstractFilterProvider
{
    /**
     * 价格筛选模式
     */
    public const MODE_PRESET = 'preset';
    public const MODE_DYNAMIC = 'dynamic';
    public const MODE_SLIDER = 'slider';
    
    /**
     * @var string 当前模式
     */
    private string $mode = self::MODE_DYNAMIC;
    
    /**
     * @var int 动态模式的区间数量
     */
    private int $dynamicRangeCount = 5;
    
    /**
     * @var string 货币符号
     */
    private string $currencySymbol = '¥';
    
    public function __construct()
    {
        $this->sortOrder = 10;
        $this->displayType = 'list';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'price';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $lang = \Weline\Framework\App\State::getLangLocal();
        $isEnglish = str_starts_with($lang, 'en');
        return $isEnglish ? 'Price Range' : __('价格区间');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        // 根据配置决定模式
        $config = $this->getCategoryConfig($categoryId);
        $mode = $config['config_data']['mode'] ?? $this->mode;
        
        switch ($mode) {
            case self::MODE_PRESET:
                return $this->getPresetOptions($categoryId, $productIds, $appliedFilters);
            case self::MODE_SLIDER:
                return $this->getSliderOptions($categoryId, $productIds, $appliedFilters);
            case self::MODE_DYNAMIC:
            default:
                return $this->getDynamicOptions($categoryId, $productIds, $appliedFilters);
        }
    }
    
    /**
     * 获取预设区间选项
     */
    private function getPresetOptions(int $categoryId, array $productIds, array $appliedFilters): array
    {
        /** @var PriceRange $priceRangeModel */
        $priceRangeModel = ObjectManager::getInstance(PriceRange::class);
        $ranges = $priceRangeModel->getPriceRanges($categoryId);
        
        if (empty($ranges)) {
            // 如果没有配置预设区间，使用默认区间
            $ranges = $this->getDefaultRanges();
        }
        
        $options = [];
        foreach ($ranges as $range) {
            $minPrice = (float)$range[PriceRange::fields_min_price];
            $maxPrice = $range[PriceRange::fields_max_price] !== null 
                ? (float)$range[PriceRange::fields_max_price] 
                : null;
            
            $value = $this->formatRangeValue($minPrice, $maxPrice);
            $label = !empty($range[PriceRange::fields_label]) 
                ? $range[PriceRange::fields_label]
                : PriceRange::generateLabel($minPrice, $maxPrice, $this->currencySymbol);
            
            $count = $this->countProductsInRange($productIds, $minPrice, $maxPrice);
            
            $options[] = $this->buildOption(
                $value,
                $label,
                $count,
                $this->isValueSelected($value, $appliedFilters)
            );
        }
        
        return $options;
    }
    
    /**
     * 获取动态计算区间选项
     */
    private function getDynamicOptions(int $categoryId, array $productIds, array $appliedFilters): array
    {
        // 获取价格范围
        $priceStats = $this->getPriceStats($productIds);
        
        if ($priceStats['min'] === null || $priceStats['max'] === null) {
            return [];
        }
        
        $minPrice = $priceStats['min'];
        $maxPrice = $priceStats['max'];
        
        // 如果价格范围很小，不显示区间
        if ($maxPrice - $minPrice < 10) {
            return [];
        }
        
        // 计算区间
        $ranges = $this->calculateDynamicRanges($minPrice, $maxPrice);
        
        $options = [];
        foreach ($ranges as $range) {
            $value = $this->formatRangeValue($range['min'], $range['max']);
            $label = PriceRange::generateLabel($range['min'], $range['max'], $this->currencySymbol);
            $count = $this->countProductsInRange($productIds, $range['min'], $range['max']);
            
            // 跳过没有产品的区间
            if ($count === 0) {
                continue;
            }
            
            $options[] = $this->buildOption(
                $value,
                $label,
                $count,
                $this->isValueSelected($value, $appliedFilters)
            );
        }
        
        return $options;
    }
    
    /**
     * 获取滑块选项
     */
    private function getSliderOptions(int $categoryId, array $productIds, array $appliedFilters): array
    {
        $priceStats = $this->getPriceStats($productIds);
        
        if ($priceStats['min'] === null || $priceStats['max'] === null) {
            return [];
        }
        
        // 获取当前选中的范围
        $selectedMin = $priceStats['min'];
        $selectedMax = $priceStats['max'];
        
        if (isset($appliedFilters['price'])) {
            $selectedRange = is_array($appliedFilters['price']) 
                ? $appliedFilters['price'][0] 
                : $appliedFilters['price'];
            
            $parsed = $this->parseRangeValue($selectedRange);
            if ($parsed) {
                $selectedMin = $parsed['min'];
                $selectedMax = $parsed['max'] ?? $priceStats['max'];
            }
        }
        
        // 返回滑块配置
        return [
            [
                'type' => 'slider',
                'value' => $this->formatRangeValue($selectedMin, $selectedMax),
                'label' => PriceRange::generateLabel($selectedMin, $selectedMax, $this->currencySymbol),
                'count' => count($productIds),
                'selected' => isset($appliedFilters['price']),
                'slider' => [
                    'min' => $priceStats['min'],
                    'max' => $priceStats['max'],
                    'current_min' => $selectedMin,
                    'current_max' => $selectedMax,
                    'step' => $this->calculateSliderStep($priceStats['min'], $priceStats['max']),
                    'currency' => $this->currencySymbol,
                ],
            ],
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
        
        // 解析所有价格范围
        $ranges = [];
        foreach ($filterValues as $value) {
            $parsed = $this->parseRangeValue($value);
            if ($parsed) {
                $ranges[] = $parsed;
            }
        }
        
        if (empty($ranges)) {
            return $productIds;
        }
        
        // 查询符合价格范围的产品
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->reset()
            ->fields(Product::fields_ID)
            ->where(Product::fields_ID, $productIds, 'in');
        
        // 使用框架的 where 方法构建价格条件，避免 SQL 注入和跨数据库兼容性问题
        // 对于多个范围，使用 OR 条件组
        if (count($ranges) === 1) {
            // 单个范围：直接使用 where
            $range = $ranges[0];
            $productModel->where(Product::fields_price, $range['min'], '>=');
            if ($range['max'] !== null) {
                $productModel->where(Product::fields_price, $range['max'], '<=');
            }
        } else {
            // 多个范围：构建 OR 条件（使用数值格式化确保兼容性）
            $priceConditions = [];
            $priceField = Product::fields_price;
            foreach ($ranges as $range) {
                $minPrice = number_format($range['min'], 2, '.', '');
                if ($range['max'] !== null) {
                    $maxPrice = number_format($range['max'], 2, '.', '');
                    $priceConditions[] = "({$priceField} >= {$minPrice} AND {$priceField} <= {$maxPrice})";
                } else {
                    $priceConditions[] = "{$priceField} >= {$minPrice}";
                }
            }
            
            if (!empty($priceConditions)) {
                $productModel->where('(' . implode(' OR ', $priceConditions) . ')');
            }
        }
        
        $results = $productModel->select()->fetchArray();
        
        return array_column($results, Product::fields_ID);
    }
    
    /**
     * @inheritDoc
     */
    public function getDisplayType(): string
    {
        $config = $this->getCategoryConfig(0);
        $mode = $config['config_data']['mode'] ?? $this->mode;
        
        return $mode === self::MODE_SLIDER ? 'slider' : 'list';
    }
    
    /**
     * 获取产品价格统计
     */
    private function getPriceStats(array $productIds): array
    {
        if (empty($productIds)) {
            return ['min' => null, 'max' => null, 'avg' => null];
        }
        
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->reset()
            ->fields([
                'MIN(' . Product::fields_price . ') as min_price',
                'MAX(' . Product::fields_price . ') as max_price',
                'AVG(' . Product::fields_price . ') as avg_price',
            ])
            ->where(Product::fields_ID, $productIds, 'in')
            ->where(Product::fields_price, 0, '>');
        
        $result = $productModel->find()->fetchArray();
        
        return [
            'min' => $result['min_price'] !== null ? (float)$result['min_price'] : null,
            'max' => $result['max_price'] !== null ? (float)$result['max_price'] : null,
            'avg' => $result['avg_price'] !== null ? (float)$result['avg_price'] : null,
        ];
    }
    
    /**
     * 计算区间内的产品数量
     */
    private function countProductsInRange(array $productIds, float $minPrice, ?float $maxPrice): int
    {
        if (empty($productIds)) {
            return 0;
        }
        
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->reset()
            ->fields('COUNT(*) as count')
            ->where(Product::fields_ID, $productIds, 'in')
            ->where(Product::fields_price, $minPrice, '>=');
        
        if ($maxPrice !== null) {
            $productModel->where(Product::fields_price, $maxPrice, '<=');
        }
        
        $result = $productModel->find()->fetchArray();
        
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * 计算动态价格区间
     */
    private function calculateDynamicRanges(float $minPrice, float $maxPrice): array
    {
        $range = $maxPrice - $minPrice;
        $rangeCount = $this->dynamicRangeCount;
        
        // 计算合适的区间大小（取整到美观的数字）
        $rawStep = $range / $rangeCount;
        $step = $this->roundToNiceNumber($rawStep);
        
        // 调整起始点
        $start = floor($minPrice / $step) * $step;
        
        $ranges = [];
        $current = $start;
        
        for ($i = 0; $i < $rangeCount; $i++) {
            $rangeMin = $current;
            $rangeMax = $current + $step;
            
            // 最后一个区间使用 null 表示无上限
            if ($i === $rangeCount - 1) {
                $rangeMax = null;
            }
            
            $ranges[] = [
                'min' => $rangeMin,
                'max' => $rangeMax,
            ];
            
            $current += $step;
        }
        
        return $ranges;
    }
    
    /**
     * 将数字四舍五入到美观的数字
     */
    private function roundToNiceNumber(float $number): float
    {
        $magnitude = pow(10, floor(log10($number)));
        $normalized = $number / $magnitude;
        
        if ($normalized < 1.5) {
            $nice = 1;
        } elseif ($normalized < 3) {
            $nice = 2;
        } elseif ($normalized < 7) {
            $nice = 5;
        } else {
            $nice = 10;
        }
        
        return $nice * $magnitude;
    }
    
    /**
     * 计算滑块步长
     */
    private function calculateSliderStep(float $min, float $max): float
    {
        $range = $max - $min;
        
        if ($range <= 100) {
            return 1;
        } elseif ($range <= 1000) {
            return 10;
        } elseif ($range <= 10000) {
            return 100;
        } else {
            return 1000;
        }
    }
    
    /**
     * 格式化价格范围值
     */
    private function formatRangeValue(float $min, ?float $max): string
    {
        if ($max === null) {
            return sprintf('%.2f-', $min);
        }
        return sprintf('%.2f-%.2f', $min, $max);
    }
    
    /**
     * 解析价格范围值
     */
    private function parseRangeValue(string $value): ?array
    {
        // 格式: "100-500" 或 "500-"
        if (preg_match('/^([\d.]+)-([\d.]*)$/', $value, $matches)) {
            return [
                'min' => (float)$matches[1],
                'max' => $matches[2] !== '' ? (float)$matches[2] : null,
            ];
        }
        return null;
    }
    
    /**
     * 获取默认价格区间
     */
    private function getDefaultRanges(): array
    {
        return [
            [PriceRange::fields_min_price => 0, PriceRange::fields_max_price => 100, PriceRange::fields_label => ''],
            [PriceRange::fields_min_price => 100, PriceRange::fields_max_price => 300, PriceRange::fields_label => ''],
            [PriceRange::fields_min_price => 300, PriceRange::fields_max_price => 500, PriceRange::fields_label => ''],
            [PriceRange::fields_min_price => 500, PriceRange::fields_max_price => 1000, PriceRange::fields_label => ''],
            [PriceRange::fields_min_price => 1000, PriceRange::fields_max_price => null, PriceRange::fields_label => ''],
        ];
    }
    
    /**
     * 设置筛选模式
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }
    
    /**
     * 设置货币符号
     */
    public function setCurrencySymbol(string $symbol): self
    {
        $this->currencySymbol = $symbol;
        return $this;
    }
    
    /**
     * 设置动态区间数量
     */
    public function setDynamicRangeCount(int $count): self
    {
        $this->dynamicRangeCount = $count;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        $parsed = $this->parseRangeValue($value);
        if ($parsed === null) {
            return $value;
        }
        
        // 使用 PriceRange 的标签生成方法
        return PriceRange::generateLabel($parsed['min'], $parsed['max'], $this->currencySymbol);
    }
}
