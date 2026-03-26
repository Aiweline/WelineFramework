<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Filters\Model\PriceRange;
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
        return __('价格区间');
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
            $minPrice = (float)$range[PriceRange::schema_fields_min_price];
            $maxPrice = $range[PriceRange::schema_fields_max_price] !== null 
                ? (float)$range[PriceRange::schema_fields_max_price] 
                : null;
            
            $value = $this->formatRangeValue($minPrice, $maxPrice);
            $label = !empty($range[PriceRange::schema_fields_label]) 
                ? $range[PriceRange::schema_fields_label]
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

        $ranges = [];
        foreach ($filterValues as $value) {
            $parsed = $this->parseRangeValue($value);
            if ($parsed) {
                $ranges[] = ['min' => $parsed['min'], 'max' => $parsed['max'] ?? null];
            }
        }

        if (empty($ranges)) {
            return $productIds;
        }

        return w_query('product', 'filterByPriceRange', [
            'product_ids' => $productIds,
            'ranges' => $ranges,
        ]);
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
     * 获取产品价格统计（通过 product 查询器，避免跨模块直接依赖）
     */
    private function getPriceStats(array $productIds): array
    {
        if (empty($productIds)) {
            return ['min' => null, 'max' => null, 'avg' => null];
        }
        return w_query('product', 'getPriceStats', ['product_ids' => $productIds]);
    }
    
    /**
     * 计算区间内的产品数量（通过 product 查询器）
     */
    private function countProductsInRange(array $productIds, float $minPrice, ?float $maxPrice): int
    {
        if (empty($productIds)) {
            return 0;
        }
        return w_query('product', 'countByPriceRange', [
            'product_ids' => $productIds,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
        ]);
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
            [PriceRange::schema_fields_min_price => 0, PriceRange::schema_fields_max_price => 100, PriceRange::schema_fields_label => ''],
            [PriceRange::schema_fields_min_price => 100, PriceRange::schema_fields_max_price => 300, PriceRange::schema_fields_label => ''],
            [PriceRange::schema_fields_min_price => 300, PriceRange::schema_fields_max_price => 500, PriceRange::schema_fields_label => ''],
            [PriceRange::schema_fields_min_price => 500, PriceRange::schema_fields_max_price => 1000, PriceRange::schema_fields_label => ''],
            [PriceRange::schema_fields_min_price => 1000, PriceRange::schema_fields_max_price => null, PriceRange::schema_fields_label => ''],
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

    public function getSearchFacetDefinition(int $categoryId, array $context = []): ?array
    {
        $configData = $this->getCategoryConfigData($categoryId);
        $currencySymbol = $this->currencySymbol;
        $rangeBuckets = is_array($configData['range_buckets'] ?? null) && $configData['range_buckets'] !== []
            ? array_values($configData['range_buckets'])
            : array_map(static fn (array $range): array => [
                'from' => $range[PriceRange::schema_fields_min_price] ?? null,
                'to' => $range[PriceRange::schema_fields_max_price] ?? null,
                'key' => sprintf(
                    '%s-%s',
                    $range[PriceRange::schema_fields_min_price] ?? '',
                    $range[PriceRange::schema_fields_max_price] ?? ''
                ),
                'label' => PriceRange::generateLabel(
                    (float) ($range[PriceRange::schema_fields_min_price] ?? 0),
                    isset($range[PriceRange::schema_fields_max_price]) ? (float) $range[PriceRange::schema_fields_max_price] : null,
                    $currencySymbol
                ),
            ], $this->getDefaultRanges());

        return [
            'code' => $this->getCode(),
            'name' => (string) $this->getName(),
            'type' => 'price',
            'field' => 'price',
            'display_type' => 'list',
            'range_buckets' => $rangeBuckets,
        ];
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
