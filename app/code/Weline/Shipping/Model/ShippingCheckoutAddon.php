<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '结账运费附加项（签名/保价）')]
#[Index(name: 'uk_shipping_checkout_addon_scope_code', columns: ['scope_type', 'scope_id', 'addon_code'], type: 'UNIQUE')]
#[Index(name: 'idx_shipping_checkout_addon_scope', columns: ['scope_type', 'scope_id', 'is_active'])]
class ShippingCheckoutAddon extends AbstractModel
{
    public const schema_table = 'w_shipping_checkout_addons';
    public const schema_primary_key = 'addon_id';

    public const SCOPE_WEBSITE = 'website';
    public const TYPE_SIGNATURE = 'signature';
    public const TYPE_INSURANCE = 'insurance';

    public const AMOUNT_FIXED = 'fixed';
    public const AMOUNT_PERCENT = 'percent';

    public const SEED_SIGNATURE = 'SEED_ADDON_SIGNATURE';
    public const SEED_INSURANCE = 'SEED_ADDON_INSURANCE';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '附加ID')]
    public const schema_fields_ID = 'addon_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 64, nullable: false, comment: '附加代码')]
    public const schema_fields_ADDON_CODE = 'addon_code';
    #[Col('varchar', 255, nullable: false, comment: '附加名称')]
    public const schema_fields_ADDON_NAME = 'addon_name';
    #[Col('varchar', 32, nullable: false, default: 'signature', comment: '类型 signature|insurance')]
    public const schema_fields_ADDON_TYPE = 'addon_type';
    #[Col('varchar', 16, nullable: false, default: 'fixed', comment: '加价方式 fixed|percent')]
    public const schema_fields_AMOUNT_TYPE = 'amount_type';
    #[Col('decimal', '12,4', nullable: false, default: 0, comment: '加价数值')]
    public const schema_fields_AMOUNT_VALUE = 'amount_value';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['addon_id'];
    public array $_index_sort_keys = ['addon_id', 'scope_type', 'scope_id', 'addon_code'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
