<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;


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
        return __('品牌');
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
            $info = $this->getProductAttributeInfo($this->attributeCode);
            if (!$info || !($info['attribute_id'] ?? 0)) {
                return [];
            }
            $brandValues = $this->getProductEavValues(
                (int)$info['attribute_id'],
                (string)($info['type_code'] ?? 'input_string'),
                $productIds
            );
            if (empty($brandValues)) {
                return [];
            }
            $brandCounts = array_count_values($brandValues);
            $options = [];
            if (!empty($info['has_option'])) {
                $optionLabels = $this->getOptionLabelsByAttributeId((int)$info['attribute_id'], array_keys($brandCounts));
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
