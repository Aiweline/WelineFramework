<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Manager\ObjectManager;

/**
 * 颜色筛选提供者
 * 
 * 支持色块(swatch)展示
 */
class ColorFilterProvider extends AbstractFilterProvider
{
    /**
     * @var string EAV属性代码
     */
    private string $attributeCode = 'color';
    
    public function __construct()
    {
        $this->sortOrder = 25;
        $this->displayType = 'swatch';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'color';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('颜色');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        try {
            $attribute = $this->getColorAttribute();
            if (!$attribute || !$attribute->getId()) {
                return [];
            }
            
            // 获取产品的颜色值
            $colorValues = $this->getProductColorValues($productIds, $attribute);
            
            if (empty($colorValues)) {
                return [];
            }
            
            // 统计每个颜色的产品数量
            $colorCounts = array_count_values($colorValues);
            
            // 获取颜色选项标签和色块
            $options = [];
            if ($attribute->hasOption()) {
                $optionLabels = $this->getOptionLabels($attribute, array_keys($colorCounts));
                foreach ($colorCounts as $value => $count) {
                    $optionInfo = $optionLabels[$value] ?? null;
                    $label = $optionInfo ? ($optionInfo['value'] ?: $optionInfo['code']) : $value;
                    
                    $option = $this->buildOption(
                        (string)$value,
                        $label,
                        $count,
                        $this->isValueSelected((string)$value, $appliedFilters)
                    );
                    
                    // 添加色块数据
                    if ($optionInfo) {
                        if (!empty($optionInfo['swatch_color'])) {
                            $option['swatch'] = [
                                'type' => 'color',
                                'value' => $optionInfo['swatch_color'],
                            ];
                        } elseif (!empty($optionInfo['swatch_image'])) {
                            $option['swatch'] = [
                                'type' => 'image',
                                'value' => $optionInfo['swatch_image'],
                            ];
                        }
                    }
                    
                    $options[] = $option;
                }
            } else {
                foreach ($colorCounts as $value => $count) {
                    $options[] = $this->buildOption(
                        (string)$value,
                        (string)$value,
                        $count,
                        $this->isValueSelected((string)$value, $appliedFilters)
                    );
                }
            }
            
            // 按数量降序排序
            usort($options, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            
            return $options;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function apply(array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues)) {
            return $productIds;
        }
        
        try {
            $attribute = $this->getColorAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $productIds;
            }
            return $this->getProductIdsByEavValues($attribute, $productIds, $filterValues);
        } catch (\Throwable $e) {
            return $productIds;
        }
    }
    
    /**
     * 获取颜色属性
     */
    private function getColorAttribute(): ?EavAttribute
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            return $productModel->getAttribute($this->attributeCode);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 获取产品的颜色值
     */
    private function getProductColorValues(array $productIds, EavAttribute $attribute): array
    {
        return $this->getProductEavValues($attribute, $productIds);
    }
    
    /**
     * 获取选项标签和色块
     */
    private function getOptionLabels(EavAttribute $attribute, array $optionIds): array
    {
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        $optionModel->reset()
            ->where('attribute_id', $attribute->getId())
            ->where('option_id', $optionIds, 'in');
        
        $results = $optionModel->select()->fetchArray();
        
        $labels = [];
        foreach ($results as $row) {
            $labels[$row['option_id']] = [
                'code' => $row['code'] ?? '',
                'value' => $row['value'] ?? '',
                'swatch_color' => $row['swatch_color'] ?? null,
                'swatch_image' => $row['swatch_image'] ?? null,
            ];
        }
        
        return $labels;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        try {
            $attribute = $this->getColorAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $value;
            }
            
            if ($attribute->hasOption()) {
                $labels = $this->getOptionLabels($attribute, [$value]);
                $info = $labels[$value] ?? null;
                return $info ? ($info['value'] ?: $info['code']) : $value;
            }
            
            return $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
