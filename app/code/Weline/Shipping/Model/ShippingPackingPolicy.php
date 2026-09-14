<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '同仓分箱策略')]
#[Index(name: 'uk_shipping_packing_scope', columns: ['scope_type', 'scope_id'], type: 'UNIQUE')]
class ShippingPackingPolicy extends AbstractModel
{
    public const schema_table = 'w_shipping_packing_policies';
    public const schema_primary_key = 'policy_id';

    public const SCOPE_WEBSITE = 'website';
    public const SEED_CODE = 'SEED_PACKING_DEFAULT';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '策略ID')]
    public const schema_fields_ID = 'policy_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 64, nullable: false, default: 'SEED_PACKING_DEFAULT', comment: '策略代码')]
    public const schema_fields_POLICY_CODE = 'policy_code';
    #[Col('decimal', '12,4', nullable: false, default: 30, comment: '单箱最大计费重kg')]
    public const schema_fields_MAX_WEIGHT_KG = 'max_weight_kg';
    #[Col('decimal', '16,4', nullable: false, default: 120000, comment: '单箱最大体积cm3')]
    public const schema_fields_MAX_VOLUME_CM3 = 'max_volume_cm3';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['policy_id'];
    public array $_index_sort_keys = ['policy_id', 'scope_type', 'scope_id'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
