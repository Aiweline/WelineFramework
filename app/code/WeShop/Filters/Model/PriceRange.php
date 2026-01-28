<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 价格区间配置模型
 */
class PriceRange extends Model
{
    public const fields_ID = 'id';
    public const fields_category_id = 'category_id';
    public const fields_min_price = 'min_price';
    public const fields_max_price = 'max_price';
    public const fields_label = 'label';
    public const fields_sort_order = 'sort_order';
    public const fields_is_enabled = 'is_enabled';
    
    /**
     * @var array 索引字段
     */
    public array $_index_sort_keys = ['id', 'category_id', 'sort_order'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('价格区间配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '区间ID'
                )
                ->addColumn(
                    self::fields_category_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '分类ID，0表示全局'
                )
                ->addColumn(
                    self::fields_min_price,
                    TableInterface::column_type_DECIMAL,
                    '12,4',
                    'not null default 0',
                    '最低价'
                )
                ->addColumn(
                    self::fields_max_price,
                    TableInterface::column_type_DECIMAL,
                    '12,4',
                    'default null',
                    '最高价，null表示无上限'
                )
                ->addColumn(
                    self::fields_label,
                    TableInterface::column_type_VARCHAR,
                    128,
                    "default ''",
                    '显示标签'
                )
                ->addColumn(
                    self::fields_sort_order,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_category_id',
                    self::fields_category_id
                )
                ->create();
        }
    }
    
    /**
     * 获取分类的价格区间
     * 
     * @param int $categoryId
     * @return array
     */
    public function getPriceRanges(int $categoryId): array
    {
        // 先获取分类特定的区间
        $this->reset()
            ->where(self::fields_category_id, $categoryId)
            ->where(self::fields_is_enabled, 1)
            ->order(self::fields_sort_order);
        $categoryRanges = $this->select()->fetchArray();
        
        if (!empty($categoryRanges)) {
            return $categoryRanges;
        }
        
        // 如果没有分类特定区间，获取全局区间
        $this->reset()
            ->where(self::fields_category_id, 0)
            ->where(self::fields_is_enabled, 1)
            ->order(self::fields_sort_order);
        return $this->select()->fetchArray();
    }
    
    /**
     * 保存价格区间
     * 
     * @param int $categoryId
     * @param float $minPrice
     * @param float|null $maxPrice
     * @param string $label
     * @param int $sortOrder
     * @return bool
     */
    public function savePriceRange(
        int $categoryId,
        float $minPrice,
        ?float $maxPrice,
        string $label = '',
        int $sortOrder = 0
    ): bool {
        $data = [
            self::fields_category_id => $categoryId,
            self::fields_min_price => $minPrice,
            self::fields_max_price => $maxPrice,
            self::fields_label => $label,
            self::fields_sort_order => $sortOrder,
            self::fields_is_enabled => 1,
        ];
        
        try {
            $this->reset()->insert($data)->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 批量保存价格区间
     * 
     * @param int $categoryId
     * @param array $ranges [[min, max, label], ...]
     * @return bool
     */
    public function savePriceRanges(int $categoryId, array $ranges): bool
    {
        // 先删除现有区间
        $this->deletePriceRanges($categoryId);
        
        $sortOrder = 0;
        foreach ($ranges as $range) {
            $minPrice = (float)($range['min'] ?? $range[0] ?? 0);
            $maxPrice = isset($range['max']) || isset($range[1]) 
                ? (float)($range['max'] ?? $range[1]) 
                : null;
            $label = $range['label'] ?? $range[2] ?? '';
            
            $this->savePriceRange($categoryId, $minPrice, $maxPrice, $label, $sortOrder++);
        }
        
        return true;
    }
    
    /**
     * 删除分类的价格区间
     * 
     * @param int $categoryId
     * @return bool
     */
    public function deletePriceRanges(int $categoryId): bool
    {
        try {
            $this->reset()
                ->where(self::fields_category_id, $categoryId)
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 生成价格区间标签
     * 
     * @param float $minPrice
     * @param float|null $maxPrice
     * @param string $currencySymbol
     * @return string
     */
    public static function generateLabel(float $minPrice, ?float $maxPrice, string $currencySymbol = ''): string
    {
        $lang = \Weline\Framework\App\State::getLangLocal();
        $isEnglish = str_starts_with($lang, 'en');
        
        if ($maxPrice === null) {
            $suffix = $isEnglish ? 'and above' : __('及以上');
            return $currencySymbol . number_format($minPrice, 0) . ' ' . $suffix;
        }
        
        if ($minPrice == 0) {
            $prefix = $isEnglish ? 'Under' : __('低于');
            return $prefix . ' ' . $currencySymbol . number_format($maxPrice, 0);
        }
        
        return $currencySymbol . number_format($minPrice, 0) . ' - ' . $currencySymbol . number_format($maxPrice, 0);
    }
}
