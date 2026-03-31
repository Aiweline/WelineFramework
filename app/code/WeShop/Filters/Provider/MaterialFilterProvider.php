<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

/**
 * 材质筛选提供者
 */
class MaterialFilterProvider extends AbstractFilterProvider
{
    /**
     * @var string EAV属性代码
     */
    private string $attributeCode = 'material';
    
    public function __construct()
    {
        $this->sortOrder = 27;
        $this->displayType = 'list';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'material';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('材质');
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
            $values = $this->getProductEavValues(
                (int)$info['attribute_id'],
                (string)($info['type_code'] ?? 'input_string'),
                $productIds
            );
            if (empty($values)) {
                return [];
            }
            $valueCounts = array_count_values($values);
            $options = [];
            if (!empty($info['has_option'])) {
                $optionLabels = $this->getOptionLabelsByAttributeId((int)$info['attribute_id'], array_keys($valueCounts));
                foreach ($valueCounts as $value => $count) {
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
                foreach ($valueCounts as $value => $count) {
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
