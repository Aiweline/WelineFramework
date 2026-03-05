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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '配送区域地区关联表')]
#[Index(name: 'idx_zone_region', columns: ['zone_id', 'region_id'], type: 'UNIQUE')]
#[Index(name: 'idx_zone_id', columns: ['zone_id'])]
#[Index(name: 'idx_region_id', columns: ['region_id'])]
class ZoneRegion extends AbstractModel
{

    public const schema_table = 'w_shipping_zone_regions';
    public const schema_primary_key = 'zone_region_id';
    public const schema_primary_keys = ['zone_region_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '关联ID')]
    public const schema_fields_ID = 'zone_region_id';
    #[Col('int', null, nullable: false, comment: '配送区域ID')]
    public const schema_fields_ZONE_ID = 'zone_id';
    #[Col('int', null, nullable: false, comment: '地区ID')]
    public const schema_fields_REGION_ID = 'region_id';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['zone_region_id', 'zone_id', 'region_id'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
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
                self::schema_fields_ZONE_ID => $zoneId,
                self::schema_fields_REGION_ID => (int)$regionId,
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
            ->where(self::schema_fields_ZONE_ID, $zoneId)
            ->delete()
            ->fetch();
    }
}


