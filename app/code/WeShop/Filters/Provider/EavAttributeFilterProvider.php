<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use Weline\Eav\Service\AttributeFilterService;
use WeShop\Filters\Api\FilterProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV属性筛选提供者
 * 
 * 动态基于EAV属性提供筛选功能
 */
class EavAttributeFilterProvider extends AbstractFilterProvider
{
    /**
     * @var string 实体代码
     */
    private string $entityCode = 'product';
    
    /**
     * @var string 属性代码
     */
    private string $attributeCode;
    
    /**
     * @var string 属性名称
     */
    private string $attributeName;
    
    /**
     * @var AttributeFilterService
     */
    private AttributeFilterService $attributeFilterService;
    
    /**
     * @var array 属性信息缓存
     */
    private ?array $attributeInfo = null;
    
    public function __construct(
        string $attributeCode,
        string $attributeName = '',
        int $sortOrder = 100
    ) {
        $this->attributeCode = $attributeCode;
        $this->attributeName = $attributeName;
        $this->sortOrder = $sortOrder;
        $this->attributeFilterService = ObjectManager::getInstance(AttributeFilterService::class);
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'attr_' . $this->attributeCode;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        if ($this->attributeName) {
            return $this->attributeName;
        }
        
        // 从属性信息中获取名称
        $info = $this->getAttributeInfo();
        return $info['name'] ?? $this->attributeCode;
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        // 获取可筛选属性数据
        $filterableData = $this->attributeFilterService->getFilterableAttributes(
            $this->entityCode,
            $productIds,
            [$this->attributeCode]
        );
        
        if (empty($filterableData[$this->attributeCode])) {
            return [];
        }
        
        $data = $filterableData[$this->attributeCode];
        $attributeData = $data['attribute'];
        $optionsData = $data['options'];
        $values = $data['values'];
        $counts = $data['counts'];
        
        $options = [];
        
        // 如果属性有预定义选项，使用选项标签
        if ($attributeData['has_option'] && !empty($optionsData)) {
            foreach ($values as $value) {
                $optionInfo = $optionsData[$value] ?? null;
                $label = $optionInfo ? ($optionInfo['value'] ?: $optionInfo['code']) : $value;
                $count = $counts[$value] ?? 0;
                
                $option = $this->buildOption(
                    (string)$value,
                    $label,
                    $count,
                    $this->isValueSelected((string)$value, $appliedFilters)
                );
                
                // 添加样本数据
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
                    } elseif (!empty($optionInfo['swatch_text'])) {
                        $option['swatch'] = [
                            'type' => 'text',
                            'value' => $optionInfo['swatch_text'],
                        ];
                    }
                }
                
                $options[] = $option;
            }
        } else {
            // 直接使用值
            foreach ($values as $value) {
                $count = $counts[$value] ?? 0;
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
    }
    
    /**
     * @inheritDoc
     */
    public function apply(array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues)) {
            return $productIds;
        }
        
        return $this->attributeFilterService->filterByAttributes(
            $this->entityCode,
            $productIds,
            [$this->attributeCode => $filterValues],
            'AND'
        );
    }
    
    /**
     * @inheritDoc
     */
    public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $counts = $this->attributeFilterService->getAttributeValueCounts(
            $this->entityCode,
            $productIds,
            [$this->attributeCode]
        );
        
        return $counts[$this->attributeCode] ?? [];
    }
    
    /**
     * @inheritDoc
     */
    public function getDisplayType(): string
    {
        $info = $this->getAttributeInfo();
        
        // 根据属性类型决定显示方式
        if (isset($info['has_swatch']) && $info['has_swatch']) {
            return 'swatch';
        }
        
        return $this->displayType;
    }
    
    /**
     * 获取属性信息
     */
    private function getAttributeInfo(): array
    {
        if ($this->attributeInfo !== null) {
            return $this->attributeInfo;
        }
        
        $filterableData = $this->attributeFilterService->getFilterableAttributes(
            $this->entityCode,
            [],
            [$this->attributeCode]
        );
        
        $this->attributeInfo = $filterableData[$this->attributeCode]['attribute'] ?? [];
        
        return $this->attributeInfo;
    }
    
    /**
     * 设置实体代码
     */
    public function setEntityCode(string $entityCode): self
    {
        $this->entityCode = $entityCode;
        return $this;
    }
    
    /**
     * 获取属性代码
     */
    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }
    
    /**
     * 创建 EAV 属性筛选提供者实例
     * 
     * @param string $attributeCode
     * @param string $attributeName
     * @param int $sortOrder
     * @return self
     */
    public static function create(
        string $attributeCode,
        string $attributeName = '',
        int $sortOrder = 100
    ): self {
        return new self($attributeCode, $attributeName, $sortOrder);
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        // 获取可筛选属性数据
        $filterableData = $this->attributeFilterService->getFilterableAttributes(
            $this->entityCode,
            [],
            [$this->attributeCode]
        );
        
        if (empty($filterableData[$this->attributeCode])) {
            return $value;
        }
        
        $data = $filterableData[$this->attributeCode];
        $attributeData = $data['attribute'] ?? [];
        $optionsData = $data['options'] ?? [];
        
        // 如果属性有预定义选项，从选项中获取翻译标签
        if (($attributeData['has_option'] ?? false) && !empty($optionsData)) {
            $optionInfo = $optionsData[$value] ?? null;
            if ($optionInfo) {
                return $optionInfo['value'] ?: ($optionInfo['code'] ?? $value);
            }
        }
        
        return $value;
    }
}
