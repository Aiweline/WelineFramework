<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Model\CategoryFilterConfig;
use Weline\Framework\Manager\ObjectManager;

/**
 * 抽象筛选提供者
 * 
 * 提供筛选器的基础实现
 */
abstract class AbstractFilterProvider implements FilterProviderInterface
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
