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

class ZoneRegion extends AbstractModel
{
    public const table = 'w_shipping_zone_regions';
    
    public const fields_ID = 'zone_region_id';
    public const fields_ZONE_ID = 'zone_id';
    public const fields_REGION_ID = 'region_id';
    public const fields_CREATED_AT = 'created_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['zone_region_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['zone_region_id', 'zone_id', 'region_id'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('配送区域地区关联表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '关联ID'
                )
                ->addColumn(
                    self::fields_ZONE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '配送区域ID'
                )
                ->addColumn(
                    self::fields_REGION_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '地区ID'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_zone_region', [self::fields_ZONE_ID, self::fields_REGION_ID], '区域地区唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_zone_id', self::fields_ZONE_ID, '区域ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_region_id', self::fields_REGION_ID, '地区ID索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 批量添加关联
     * 
     * @param int $zoneId 配送区域ID
     * @param array $regionIds 地区ID数组
     * @return int 成功添加的数量
     */
    public function batchAdd(int $zoneId, array $regionIds): int
    {
        $count = 0;
        foreach ($regionIds as $regionId) {
            $model = $this->reset();
            $model->setData([
                self::fields_ZONE_ID => $zoneId,
                self::fields_REGION_ID => (int)$regionId,
            ]);
            try {
                $model->save();
                $count++;
            } catch (\Exception $e) {
                // 忽略重复插入错误
                continue;
            }
        }
        return $count;
    }

    /**
     * 删除区域的所有地区关联
     * 
     * @param int $zoneId 配送区域ID
     * @return bool
     */
    public function deleteByZoneId(int $zoneId): bool
    {
        return $this->reset()
            ->where(self::fields_ZONE_ID, $zoneId)
            ->delete()
            ->fetch();
    }
}

