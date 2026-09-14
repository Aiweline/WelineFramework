<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Website-scoped commerce edges: return_policy + split_shipment_shipping.
 * Keys align with plan ScopeConfig names shipping.return_policy / shipping.split_shipment_shipping.
 */
#[Table(comment: '运费履约周边策略')]
#[Index(name: 'uk_shipping_commerce_scope', columns: ['scope_type', 'scope_id'], type: 'UNIQUE')]
class ShippingCommercePolicy extends AbstractModel
{
    public const schema_table = 'w_shipping_commerce_policies';
    public const schema_primary_key = 'policy_id';

    public const SCOPE_WEBSITE = 'website';
    public const SEED_CODE = 'SEED_COMMERCE_DEFAULT';

    public const RETURN_BUYER = 'buyer_pays';
    public const RETURN_SELLER = 'seller_pays';
    public const RETURN_SPLIT_50 = 'split_50';

    public const SPLIT_FIRST_ONLY = 'first_only';
    public const SPLIT_EACH = 'each_shipment';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '策略ID')]
    public const schema_fields_ID = 'policy_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 64, nullable: false, default: 'SEED_COMMERCE_DEFAULT', comment: '策略代码')]
    public const schema_fields_POLICY_CODE = 'policy_code';
    #[Col('varchar', 32, nullable: false, default: 'buyer_pays', comment: '退货运费策略')]
    public const schema_fields_RETURN_POLICY = 'return_policy';
    #[Col('varchar', 32, nullable: false, default: 'first_only', comment: '同单分批发策略')]
    public const schema_fields_SPLIT_SHIPMENT_SHIPPING = 'split_shipment_shipping';
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
