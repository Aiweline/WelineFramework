<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Manager\ObjectManager;

/**
 * 品牌筛选提供者
 * 
 * 支持两种模式：
 * 1. EAV属性模式 - 品牌作为产品的EAV属性
 * 2. 独立模块模式 - 使用独立的品牌模块
 */
class BrandFilterProvider extends AbstractFilterProvider
{
    /**
     * 品牌来源模式
     */
    public const MODE_EAV = 'eav';
    public const MODE_MODULE = 'module';
    
    /**
     * @var string 当前模式
     */
    private string $mode = self::MODE_EAV;
    
    /**
     * @var string EAV属性代码
     */
    private string $attributeCode = 'brand';
    
    public function __construct()
    {
        $this->sortOrder = 20;
        $this->displayType = 'list';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'brand';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $lang = \Weline\Framework\App\State::getLangLocal();
        $isEnglish = str_starts_with($lang, 'en');
        return $isEnglish ? 'Brand' : __('品牌');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $config = $this->getCategoryConfig($categoryId);
        $mode = $config['config_data']['mode'] ?? $this->mode;
        
        if ($mode === self::MODE_MODULE) {
            return $this->getModuleBrandOptions($productIds, $appliedFilters);
        }
        
        return $this->getEavBrandOptions($productIds, $appliedFilters);
    }
    
    /**
     * 获取EAV属性品牌选项
     */
    private function getEavBrandOptions(array $productIds, array $appliedFilters): array
    {
        try {
            // 获取品牌属性
            $attribute = $this->getBrandAttribute();
            if (!$attribute || !$attribute->getId()) {
                return [];
            }
            
            // 获取产品的品牌值
            $brandValues = $this->getProductBrandValues($productIds, $attribute);
            
            if (empty($brandValues)) {
                return [];
            }
            
            // 统计每个品牌的产品数量
            $brandCounts = array_count_values($brandValues);
            
            // 获取品牌选项标签
            $options = [];
            if ($attribute->hasOption()) {
                // 从选项表获取标签
                $optionLabels = $this->getOptionLabels($attribute, array_keys($brandCounts));
                foreach ($brandCounts as $value => $count) {
                    $label = $optionLabels[$value] ?? $value;
                    $options[] = $this->buildOption(
                        (string)$value,
                        $label,
                        $count,
                        $this->isValueSelected((string)$value, $appliedFilters)
                    );
                }
            } else {
                // 直接使用值作为标签
                foreach ($brandCounts as $value => $count) {
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
     * 获取模块品牌选项（预留扩展）
     */
    private function getModuleBrandOptions(array $productIds, array $appliedFilters): array
    {
        // 如果有独立的品牌模块，在这里实现
        // 目前回退到EAV模式
        return $this->getEavBrandOptions($productIds, $appliedFilters);
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
            $attribute = $this->getBrandAttribute();
            if (!$attribute || !$attribute->getId()) {
                return $productIds;
            }
            
            // 获取品牌属性值表
            $valueModel = $attribute->w_getValueModel();
            $valueTable = $valueModel->getTable();
            
            // 查询符合品牌的产品
            $valueModel->reset()
                ->fields('entity_id')
                ->where('attribute_id', $attribute->getId())
                ->where('value', $filterValues, 'in')
                ->where('entity_id', $productIds, 'in');
            
            $results = $valueModel->select()->fetchArray();
            
            if (empty($results)) {
                return [];
            }
            
            return array_unique(array_column($results, 'entity_id'));
        } catch (\Throwable $e) {
            return $productIds;
        }
    }
    
    /**
     * 获取品牌属性
     */
    private function getBrandAttribute(): ?EavAttribute
    {
        try {
            // 获取产品实体的品牌属性
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            return $productModel->getAttribute($this->attributeCode);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 获取产品的品牌值
     */
    private function getProductBrandValues(array $productIds, EavAttribute $attribute): array
    {
        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields(['entity_id', 'value'])
            ->where('attribute_id', $attribute->getId())
            ->where('entity_id', $productIds, 'in');
        
        $results = $valueModel->select()->fetchArray();
        
        $values = [];
        foreach ($results as $row) {
            $values[] = $row['value'];
        }
        
        return $values;
    }
    
    /**
     * 获取选项标签
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
            $labels[$row['option_id']] = $row['value'] ?? $row['code'];
        }
        
        return $labels;
    }
    
    /**
     * 设置品牌属性代码
     */
    public function setAttributeCode(string $code): self
    {
        $this->attributeCode = $code;
        return $this;
    }
    
    /**
     * 设置品牌来源模式
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        try {
            $attribute = $this->getBrandAttribute();
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
