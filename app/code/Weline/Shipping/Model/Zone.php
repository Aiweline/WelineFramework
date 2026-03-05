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
#[Table(comment: '配送区域表')]
#[Index(name: 'idx_zone_code', columns: ['zone_code'], type: 'UNIQUE')]
class Zone extends AbstractModel
{

    public const schema_table = 'w_shipping_zones';
    public const schema_primary_key = 'zone_id';
    public const schema_primary_keys = ['zone_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '配送区域ID')]
    public const schema_fields_ID = 'zone_id';
    #[Col('varchar', 255, nullable: false, comment: '区域名称')]
    public const schema_fields_ZONE_NAME = 'zone_name';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '区域代码')]
    public const schema_fields_ZONE_CODE = 'zone_code';
    #[Col('text', comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['zone_id', 'zone_code'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }
}


