<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 分类筛选配置模型
 * 
 * 存储每个分类的筛选配置
 */
class CategoryFilterConfig extends Model
{
    public const fields_ID = 'id';
    public const fields_category_id = 'category_id';
    public const fields_filter_code = 'filter_code';
    public const fields_attribute_id = 'attribute_id';
    public const fields_sort_order = 'sort_order';
    public const fields_is_enabled = 'is_enabled';
    public const fields_display_type = 'display_type';
    public const fields_is_collapsed = 'is_collapsed';
    public const fields_inherit_parent = 'inherit_parent';
    public const fields_config_data = 'config_data';
    
    /**
     * @var array 唯一键
     */
    public array $_unit_primary_keys = ['category_id', 'filter_code'];
    
    /**
     * @var array 索引字段
     */
    public array $_index_sort_keys = ['id', 'category_id', 'filter_code', 'attribute_id', 'sort_order'];
    
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
            $setup->createTable('分类筛选配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '配置ID'
                )
                ->addColumn(
                    self::fields_category_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '分类ID，0表示全局配置'
                )
                ->addColumn(
                    self::fields_filter_code,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'not null',
                    '筛选器代码'
                )
                ->addColumn(
                    self::fields_attribute_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default null',
                    'EAV属性ID（仅用于属性筛选）'
                )
                ->addColumn(
                    self::fields_sort_order,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 100',
                    '排序权重'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_display_type,
                    TableInterface::column_type_VARCHAR,
                    32,
                    "default 'list'",
                    '显示类型：list/swatch/slider/checkbox/radio'
                )
                ->addColumn(
                    self::fields_is_collapsed,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '默认折叠'
                )
                ->addColumn(
                    self::fields_inherit_parent,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否继承父分类配置'
                )
                ->addColumn(
                    self::fields_config_data,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '额外配置数据JSON'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_category_filter',
                    [self::fields_category_id, self::fields_filter_code]
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_category_id',
                    self::fields_category_id
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_attribute_id',
                    self::fields_attribute_id
                )
                ->create();
        }
    }
    
    /**
     * 获取分类的筛选配置
     * 
     * @param int $categoryId
     * @param string|null $filterCode
     * @return array|null
     */
    public function getFilterConfig(int $categoryId, ?string $filterCode = null): ?array
    {
        $this->reset();
        
        if ($filterCode !== null) {
            $this->where(self::fields_category_id, $categoryId)
                ->where(self::fields_filter_code, $filterCode);
            $result = $this->find()->fetchArray();
            return !empty($result) ? $result : null;
        }
        
        $this->where(self::fields_category_id, $categoryId)
            ->order(self::fields_sort_order);
        return $this->select()->fetchArray();
    }
    
    /**
     * 获取分类的所有启用的筛选配置
     * 
     * @param int $categoryId
     * @param bool $includeInherited 是否包含继承的配置
     * @return array
     */
    public function getEnabledFilters(int $categoryId, bool $includeInherited = true): array
    {
        $configs = [];
        
        // 获取当前分类的配置
        $this->reset()
            ->where(self::fields_category_id, $categoryId)
            ->where(self::fields_is_enabled, 1)
            ->order(self::fields_sort_order);
        $categoryConfigs = $this->select()->fetchArray();
        
        foreach ($categoryConfigs as $config) {
            $configs[$config[self::fields_filter_code]] = $config;
        }
        
        // 获取全局配置（category_id = 0）
        if ($includeInherited) {
            $this->reset()
                ->where(self::fields_category_id, 0)
                ->where(self::fields_is_enabled, 1)
                ->order(self::fields_sort_order);
            $globalConfigs = $this->select()->fetchArray();
            
            foreach ($globalConfigs as $config) {
                $filterCode = $config[self::fields_filter_code];
                // 不覆盖分类特定配置
                if (!isset($configs[$filterCode])) {
                    $configs[$filterCode] = $config;
                }
            }
        }
        
        // 按 sort_order 排序
        uasort($configs, function ($a, $b) {
            return ($a[self::fields_sort_order] ?? 100) <=> ($b[self::fields_sort_order] ?? 100);
        });
        
        return array_values($configs);
    }
    
    /**
     * 保存筛选配置
     * 
     * @param int $categoryId
     * @param string $filterCode
     * @param array $data
     * @return bool
     */
    public function saveFilterConfig(int $categoryId, string $filterCode, array $data): bool
    {
        $this->reset();
        
        $data[self::fields_category_id] = $categoryId;
        $data[self::fields_filter_code] = $filterCode;
        
        if (isset($data[self::fields_config_data]) && is_array($data[self::fields_config_data])) {
            $data[self::fields_config_data] = json_encode($data[self::fields_config_data]);
        }
        
        try {
            $this->insert($data, [self::fields_category_id, self::fields_filter_code])->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 删除筛选配置
     * 
     * @param int $categoryId
     * @param string|null $filterCode
     * @return bool
     */
    public function deleteFilterConfig(int $categoryId, ?string $filterCode = null): bool
    {
        $this->reset()
            ->where(self::fields_category_id, $categoryId);
        
        if ($filterCode !== null) {
            $this->where(self::fields_filter_code, $filterCode);
        }
        
        try {
            $this->delete()->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
