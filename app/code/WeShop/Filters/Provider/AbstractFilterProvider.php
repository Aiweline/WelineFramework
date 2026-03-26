<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Api\SearchFacetCapableFilterInterface;
use WeShop\Filters\Model\CategoryFilterConfig;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Manager\ObjectManager;

/**
 * 抽象筛选提供者
 * 
 * 提供筛选器的基础实现
 */
abstract class AbstractFilterProvider implements FilterProviderInterface, SearchFacetCapableFilterInterface
{
    /**
     * @var int 默认排序权重
     */
    protected int $sortOrder = 100;
    
    /**
     * @var string 显示类型
     */
    protected string $displayType = 'list';
    
    /**
     * @var bool 默认折叠
     */
    protected bool $collapsed = false;
    
    /**
     * @var string|null 图标
     */
    protected ?string $icon = null;
    
    /**
     * @var bool 全局启用状态
     */
    protected bool $enabled = true;
    
    /**
     * @var array 分类配置缓存
     */
    protected array $categoryConfigCache = [];
    
    /**
     * @inheritDoc
     */
    abstract public function getCode(): string;
    
    /**
     * @inheritDoc
     */
    abstract public function getName(): string;
    
    /**
     * @inheritDoc
     */
    abstract public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array;
    
    /**
     * @inheritDoc
     */
    abstract public function apply(array $productIds, array $filterValues): array;
    
    /**
     * @inheritDoc
     */
    public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        // 默认实现：从 getOptions 中提取计数
        $options = $this->getOptions($categoryId, $productIds, $appliedFilters);
        $counts = [];
        foreach ($options as $option) {
            if (isset($option['value']) && isset($option['count'])) {
                $counts[$option['value']] = $option['count'];
            }
        }
        return $counts;
    }
    
    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    
    /**
     * 设置排序权重
     * 
     * @param int $sortOrder
     * @return self
     */
    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function isEnabled(int $categoryId): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        // 检查分类特定配置
        $config = $this->getCategoryConfig($categoryId);
        if ($config !== null) {
            return (bool)($config['is_enabled'] ?? true);
        }
        
        return true;
    }
    
    /**
     * 设置全局启用状态
     * 
     * @param bool $enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getDisplayType(): string
    {
        return $this->displayType;
    }
    
    /**
     * 设置显示类型
     * 
     * @param string $displayType
     * @return self
     */
    public function setDisplayType(string $displayType): self
    {
        $this->displayType = $displayType;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }
    
    /**
     * 设置默认折叠
     * 
     * @param bool $collapsed
     * @return self
     */
    public function setCollapsed(bool $collapsed): self
    {
        $this->collapsed = $collapsed;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getIcon(): ?string
    {
        return $this->icon;
    }
    
    /**
     * 设置图标
     * 
     * @param string|null $icon
     * @return self
     */
    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }
    
    /**
     * 从产品 EAV 值表读取属性值（通过 product 查询器，避免跨模块直接依赖）
     */
    protected function getProductEavValues(int $attributeId, string $typeCode, array $productIds): array
    {
        if (empty($productIds) || $attributeId <= 0) {
            return [];
        }
        try {
            return w_query('product', 'getProductEavValues', [
                'attribute_id' => $attributeId,
                'type_code' => $typeCode,
                'product_ids' => $productIds,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 从产品 EAV 值表查询符合属性值的 entity_id 列表（用于 apply 筛选，通过 product 查询器）
     */
    protected function getProductIdsByEavValues(int $attributeId, string $typeCode, array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues) || $attributeId <= 0) {
            return [];
        }
        try {
            return w_query('product', 'filterByEavAttribute', [
                'attribute_id' => $attributeId,
                'type_code' => $typeCode,
                'product_ids' => $productIds,
                'filter_values' => $filterValues,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 根据 attribute_id 获取选项标签（使用 Eav Option，无需 Product 模块）
     */
    protected function getOptionLabelsByAttributeId(int $attributeId, array $optionIds): array
    {
        if (empty($optionIds) || $attributeId <= 0) {
            return [];
        }
        try {
            /** @var Option $optionModel */
            $optionModel = ObjectManager::getInstance(Option::class);
            $optionModel->reset()
                ->where('attribute_id', $attributeId)
                ->where('option_id', $optionIds, 'in');
            $results = $optionModel->select()->fetchArray();
            $labels = [];
            foreach ($results as $row) {
                $oid = $row['option_id'] ?? null;
                if ($oid !== null) {
                    $labels[$oid] = [
                        'code' => $row['code'] ?? '',
                        'value' => $row['value'] ?? '',
                        'swatch_color' => $row['swatch_color'] ?? null,
                        'swatch_image' => $row['swatch_image'] ?? null,
                    ];
                }
            }
            return $labels;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取产品 EAV 属性信息（通过 product 查询器）
     * @return array{attribute_id: int, type_code: string, has_option: bool}|null
     */
    protected function getProductAttributeInfo(string $attributeCode): ?array
    {
        try {
            $info = w_query('product', 'getAttributeInfo', ['attribute_code' => $attributeCode]);
            return is_array($info) && isset($info['attribute_id']) ? $info : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取分类配置
     * 
     * @param int $categoryId
     * @return array|null
     */
    protected function getCategoryConfig(int $categoryId): ?array
    {
        if (!isset($this->categoryConfigCache[$categoryId])) {
            try {
                /** @var CategoryFilterConfig $configModel */
                $configModel = ObjectManager::getInstance(CategoryFilterConfig::class);
                $config = $configModel->getFilterConfig($categoryId, $this->getCode());
                $this->categoryConfigCache[$categoryId] = $config;
            } catch (\Throwable $e) {
                $this->categoryConfigCache[$categoryId] = null;
            }
        }
        return $this->categoryConfigCache[$categoryId];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCategoryConfigData(int $categoryId): array
    {
        $config = $this->getCategoryConfig($categoryId);
        $configData = $config['config_data'] ?? [];

        return is_array($configData) ? $configData : [];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function getSearchFacetDefinition(int $categoryId, array $context = []): ?array
    {
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $buckets
     * @param array<string, mixed> $appliedFilters
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSearchFacetBuckets(array $buckets, array $appliedFilters = [], array $context = []): array
    {
        $options = [];

        foreach ($buckets as $bucket) {
            $value = (string) ($bucket['value'] ?? $bucket['key'] ?? '');
            if ($value === '') {
                continue;
            }

            $option = $this->buildOption(
                $value,
                (string) ($bucket['label'] ?? $value),
                (int) ($bucket['count'] ?? $bucket['doc_count'] ?? 0),
                $this->isValueSelected($value, $appliedFilters)
            );

            $swatch = [];
            if (!empty($bucket['swatch_color'])) {
                $swatch = ['type' => 'color', 'value' => (string) $bucket['swatch_color']];
            } elseif (!empty($bucket['swatch_image'])) {
                $swatch = ['type' => 'image', 'value' => (string) $bucket['swatch_image']];
            } elseif (!empty($bucket['swatch_text'])) {
                $swatch = ['type' => 'text', 'value' => (string) $bucket['swatch_text']];
            }

            if ($swatch !== []) {
                $option['swatch'] = $swatch;
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    protected function buildEavFacetDefinition(
        string $attributeCode,
        int $categoryId,
        array $context = [],
        ?string $code = null,
        ?string $name = null,
        ?string $displayType = null
    ): ?array {
        $info = $this->getProductAttributeInfo($attributeCode);
        if (!$info || empty($info['attribute_id'])) {
            return null;
        }

        $configData = $this->getCategoryConfigData($categoryId);

        return [
            'code' => $code ?? $this->getCode(),
            'name' => $name ?? (string) ($info['name'] ?? $this->getName()),
            'type' => 'eav',
            'attribute_id' => (int) ($info['attribute_id'] ?? 0),
            'attribute_code' => $attributeCode,
            'display_type' => $displayType ?? $this->getDisplayType(),
            'facet_mode' => (string) ($configData[CategoryFilterConfig::CONFIG_FACET_MODE] ?? 'terms'),
            'range_buckets' => is_array($configData[CategoryFilterConfig::CONFIG_RANGE_BUCKETS] ?? null)
                ? array_values($configData[CategoryFilterConfig::CONFIG_RANGE_BUCKETS])
                : [],
            'bucket_size' => max(1, (int) ($configData[CategoryFilterConfig::CONFIG_BUCKET_SIZE] ?? 20)),
            'has_option' => !empty($info['has_option']),
            'is_multiple' => !empty($info['is_multiple']),
            'type_code' => (string) ($info['type_code'] ?? ''),
        ];
    }
    
    /**
     * 构建筛选选项
     * 
     * @param string $value 选项值
     * @param string $label 显示标签
     * @param int $count 产品数量
     * @param bool $selected 是否已选中
     * @param array $swatch 样本数据
     * @return array
     */
    protected function buildOption(
        string $value,
        string $label,
        int $count = 0,
        bool $selected = false,
        array $swatch = []
    ): array {
        $option = [
            'value' => $value,
            'label' => $label,
            'count' => $count,
            'selected' => $selected,
        ];
        
        if (!empty($swatch)) {
            $option['swatch'] = $swatch;
        }
        
        return $option;
    }
    
    /**
     * 检查值是否在已应用的筛选中
     * 
     * @param string $value
     * @param array $appliedFilters
     * @return bool
     */
    protected function isValueSelected(string $value, array $appliedFilters): bool
    {
        $filterCode = $this->getCode();
        if (!isset($appliedFilters[$filterCode])) {
            return false;
        }
        
        $appliedValues = $appliedFilters[$filterCode];
        if (!is_array($appliedValues)) {
            $appliedValues = [$appliedValues];
        }
        
        return in_array($value, $appliedValues, true);
    }
    
    /**
     * @inheritDoc
     * 
     * 默认实现：直接返回值
     * 子类应该重写此方法以提供翻译后的标签
     */
    public function getValueLabel(string $value): string
    {
        return $value;
    }
}
