<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Manager\ObjectManager;

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
            $attribute = $this->getSizeAttribute();
            if (!$attribute || !$attribute->getId()) {
                return [];
            }
            
            $sizeValues = $this->getProductSizeValues($productIds, $attribute);
            
            if (empty($sizeValues)) {
                return [];
            }
            
            $sizeCounts = array_count_values($sizeValues);
            
            $options = [];
            if ($attribute->hasOption()) {
                $optionLabels = $this->getOptionLabels($attribute, array_keys($sizeCounts));
                foreach ($sizeCounts as $value => $count) {
                    $label = $optionLabels[$value] ?? $value;
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
            $attribute = $this->getSizeAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $productIds;
            }
            return $this->getProductIdsByEavValues($attribute, $productIds, $filterValues);
        } catch (\Throwable $e) {
            return $productIds;
        }
    }
    
    private function getSizeAttribute(): ?EavAttribute
    {
        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            return $productModel->getAttribute($this->attributeCode);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getProductSizeValues(array $productIds, EavAttribute $attribute): array
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
            $attribute = $this->getSizeAttribute();
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
