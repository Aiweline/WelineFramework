<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Manager\ObjectManager;

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
            $attribute = $this->getMaterialAttribute();
            if (!$attribute || !$attribute->getId()) {
                return [];
            }
            
            $values = $this->getProductMaterialValues($productIds, $attribute);
            
            if (empty($values)) {
                return [];
            }
            
            $valueCounts = array_count_values($values);
            
            $options = [];
            if ($attribute->hasOption()) {
                $optionLabels = $this->getOptionLabels($attribute, array_keys($valueCounts));
                foreach ($valueCounts as $value => $count) {
                    $label = $optionLabels[$value] ?? $value;
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
            $attribute = $this->getMaterialAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $productIds;
            }
            return $this->getProductIdsByEavValues($attribute, $productIds, $filterValues);
        } catch (\Throwable $e) {
            return $productIds;
        }
    }
    
    private function getMaterialAttribute(): ?EavAttribute
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            return $productModel->getAttribute($this->attributeCode);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getProductMaterialValues(array $productIds, EavAttribute $attribute): array
    {
        return $this->getProductEavValues($attribute, $productIds);
    }
    
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
            $labels[$row['option_id']] = $row['value'] ?? $row['code'];
        }
        
        return $labels;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        try {
            $attribute = $this->getMaterialAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $value;
            }
            
            if ($attribute->hasOption()) {
                $labels = $this->getOptionLabels($attribute, [$value]);
                return $labels[$value] ?? $value;
            }
            
            return $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
