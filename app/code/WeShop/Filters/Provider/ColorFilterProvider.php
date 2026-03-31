<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

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
            $info = $this->getProductAttributeInfo($this->attributeCode);
            if (!$info || !($info['attribute_id'] ?? 0)) {
                return $this->getSearchBackedOptionsFallback($categoryId, $appliedFilters);
            }
            $colorValues = $this->getProductEavValues(
                (int)$info['attribute_id'],
                (string)($info['type_code'] ?? 'input_string'),
                $productIds
            );
            if (empty($colorValues)) {
                return [];
            }
            $colorCounts = array_count_values($colorValues);
            $options = [];
            if (!empty($info['has_option'])) {
                $optionLabels = $this->getOptionLabelsByAttributeId((int)$info['attribute_id'], array_keys($colorCounts));
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
            return $this->getSearchBackedOptionsFallback($categoryId, $appliedFilters);
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
            $info = $this->getProductAttributeInfo($this->attributeCode);
            if (!$info || !($info['attribute_id'] ?? 0)) {
                return $productIds;
            }
            return $this->getProductIdsByEavValues(
                (int)$info['attribute_id'],
                (string)($info['type_code'] ?? 'input_string'),
                $productIds,
                $filterValues
            );
        } catch (\Throwable $e) {
            return $productIds;
        }
    }

    public function getSearchFacetDefinition(int $categoryId, array $context = []): ?array
    {
        return $this->buildEavFacetDefinition(
            $this->attributeCode,
            $categoryId,
            $context,
            $this->getCode(),
            (string) $this->getName(),
            $this->getDisplayType()
        );
    }
    
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        try {
            $info = $this->getProductAttributeInfo($this->attributeCode);
            if (!$info || !($info['attribute_id'] ?? 0) || empty($info['has_option'])) {
                return $value;
            }
            $labels = $this->getOptionLabelsByAttributeId((int)$info['attribute_id'], [$value]);
            $labelInfo = $labels[$value] ?? null;
            return $labelInfo ? ($labelInfo['value'] ?: $labelInfo['code']) : $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
