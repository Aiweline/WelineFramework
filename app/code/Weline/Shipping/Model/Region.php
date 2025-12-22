<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Region extends AbstractModel
{
    public const table = 'w_shipping_regions';
    
    public const fields_ID = 'region_id';
    public const fields_COUNTRY_CODE = 'country_code';
    public const fields_PARENT_REGION_ID = 'parent_region_id';
    public const fields_REGION_CODE = 'region_code';
    public const fields_REGION_NAME = 'region_name';
    public const fields_REGION_TYPE = 'region_type';
    public const fields_POSTAL_CODE_PATTERN = 'postal_code_pattern';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 地区类型常量
    public const TYPE_COUNTRY = 'country';
    public const TYPE_PROVINCE = 'province';
    public const TYPE_CITY = 'city';
    public const TYPE_DISTRICT = 'district';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['region_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['region_id', 'country_code', 'parent_region_id', 'region_type'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 获取表名
     */
    public function getTable(string $table = ''): string
    {
        return self::table;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('配送地区表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '地区ID'
                )
                ->addColumn(
                    self::fields_COUNTRY_CODE,
                    TableInterface::column_type_VARCHAR,
                    2,
                    'not null',
                    'ISO国家代码，关联i18n_countries.code'
                )
                ->addColumn(
                    self::fields_PARENT_REGION_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '父级地区ID（支持层级结构）'
                )
                ->addColumn(
                    self::fields_REGION_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '地区代码（如省代码、市代码）'
                )
                ->addColumn(
                    self::fields_REGION_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '地区名称'
                )
                ->addColumn(
                    self::fields_REGION_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '地区类型：country/province/city/district'
                )
                ->addColumn(
                    self::fields_POSTAL_CODE_PATTERN,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'default null',
                    '邮政编码格式（正则表达式）'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_country_code', self::fields_COUNTRY_CODE, '国家代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_parent_region_id', self::fields_PARENT_REGION_ID, '父级地区ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_region_code', self::fields_REGION_CODE, '地区代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_region_type', self::fields_REGION_TYPE, '地区类型索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 获取子地区列表
     * 
     * @param int|null $parentRegionId 父级地区ID，null表示获取顶级地区
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getChildren(?int $parentRegionId = null): \Weline\Framework\Database\Model\Collection
    {
        $model = $this->reset();
        if ($parentRegionId === null) {
            $model->where(self::fields_PARENT_REGION_ID, null, 'IS');
        } else {
            $model->where(self::fields_PARENT_REGION_ID, $parentRegionId);
        }
        $model->where(self::fields_IS_ACTIVE, 1)
              ->order(self::fields_SORT_ORDER, 'ASC')
              ->order(self::fields_REGION_NAME, 'ASC');
        return $model->select()->fetch();
    }

    /**
     * 根据国家代码获取地区列表
     * 
     * @param string $countryCode ISO国家代码
     * @param string|null $regionType 地区类型筛选
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getByCountryCode(string $countryCode, ?string $regionType = null): \Weline\Framework\Database\Model\Collection
    {
        $model = $this->reset()
            ->where(self::fields_COUNTRY_CODE, $countryCode);
        
        if ($regionType !== null) {
            $model->where(self::fields_REGION_TYPE, $regionType);
        }
        
        $model->where(self::fields_IS_ACTIVE, 1)
              ->order(self::fields_SORT_ORDER, 'ASC')
              ->order(self::fields_REGION_NAME, 'ASC');
        
        return $model->select()->fetch();
    }

    /**
     * 获取父级地区
     * 
     * @return Region|null
     */
    public function getParent(): ?Region
    {
        $parentId = $this->getData(self::fields_PARENT_REGION_ID);
        if (!$parentId) {
            return null;
        }
        return $this->reset()->load($parentId);
    }

    /**
     * 获取完整地区路径（如：中国 > 北京 > 朝阳区）
     * 
     * @return string
     */
    public function getFullPath(): string
    {
        $path = [];
        $region = $this;
        
        while ($region && $region->getId()) {
            array_unshift($path, $region->getData(self::fields_REGION_NAME));
            $region = $region->getParent();
        }
        
        return implode(' > ', $path);
    }
}

