<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 免邮条件类型字典：主表存默认名称；多语言见 {@see FreeShippingConditionType\LocalDescription}。
 */
#[Table(comment: '免邮条件类型字典')]
#[Index(name: 'uk_shipping_free_condition_code', columns: ['condition_code'], type: 'UNIQUE')]
#[Index(name: 'idx_shipping_free_condition_active_sort', columns: ['is_active', 'sort_order'])]
class FreeShippingConditionType extends AbstractModel
{
    public const schema_table = 'w_shipping_free_condition_types';
    public const schema_primary_key = 'type_id';
    public const schema_primary_keys = ['type_id'];

    public const ORIGIN_SEED = 'seed';
    public const ORIGIN_MANUAL = 'manual';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '类型ID')]
    public const schema_fields_ID = 'type_id';

    #[Col('varchar', 32, nullable: false, comment: '条件码 order_amount|region|mixed…')]
    public const schema_fields_CONDITION_CODE = 'condition_code';

    #[Col('varchar', 255, nullable: false, default: '', comment: '条件默认名称（源语言回退）')]
    public const schema_fields_CONDITION_NAME = 'condition_name';

    #[Col('varchar', 16, nullable: false, default: 'manual', comment: '来源 seed|manual')]
    public const schema_fields_ORIGIN = 'origin';

    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', null, nullable: false, default: 100, comment: '排序（小在前）')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['sort_order', 'type_id'];
}
