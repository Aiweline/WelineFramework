<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

/**
 * 尺寸筛选提供者
 */
class SizeFilterProvider extends AbstractFilterProvider
{
    /**
     * @var string EAV属性代码
     */
    private string $attributeCode = 'size';
    
    public function __construct()
    {
        $this->sortOrder = 26;
        $this->displayType = 'list';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'size';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('尺寸');
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
                return [];
            }
            $sizeValues = $this->getProductEavValues(
                (int)$info['attribute_id'],
                (string)($info['type_code'] ?? 'input_string'),
                $productIds
            );
            if (empty($sizeValues)) {
                return [];
            }
            $sizeCounts = array_count_values($sizeValues);
            $options = [];
            if (!empty($info['has_option'])) {
                $optionLabels = $this->getOptionLabelsByAttributeId((int)$info['attribute_id'], array_keys($sizeCounts));
                foreach ($sizeCounts as $value => $count) {
                    $labelInfo = $optionLabels[$value] ?? null;
                    $label = $labelInfo ? ($labelInfo['value'] ?: $labelInfo['code']) : $value;
                    $options[] = $this->buildOption(
                        (string)$value,
                        $label,
                        $count,
                        $this->isValueSelected((string)$value, $appliedFilters)
                    );
                }
            } else {
                foreach ($sizeCounts as $value => $count) {
                    $options[] = $this->buildOption(
                        (string)$value,
                        (string)$value,
                        $count,
                        $this->isValueSelected((string)$value, $appliedFilters)
                    );
                }
            }
            
            // 按尺寸排序（尝试按自然排序）
            usort($options, function ($a, $b) {
                return strnatcmp($a['label'], $b['label']);
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
