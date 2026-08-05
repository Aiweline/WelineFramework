<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Fulfillment unit stub（Group→Order→Fulfillment topology；仓维细节归 P3）.
 */
#[Table(comment: 'Order fulfillment unit')]
#[Index(name: 'uk_fulfillment_unit_uuid', columns: ['fulfillment_unit_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_fulfillment_order', columns: ['order_uuid'])]
#[Index(name: 'idx_fulfillment_checkout_group', columns: ['checkout_group_uuid'])]
#[Index(name: 'idx_fulfillment_warehouse', columns: ['warehouse_id', 'status'])]
class FulfillmentUnit extends Model
{
    public const schema_table = 'weline_fulfillment_unit';
    public const schema_primary_key = 'fulfillment_unit_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'fulfillment_unit_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Unit UUID')]
    public const schema_fields_FULFILLMENT_UNIT_UUID = 'fulfillment_unit_uuid';

    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('varchar', 36, nullable: true, comment: 'Checkout group UUID')]
    public const schema_fields_CHECKOUT_GROUP_UUID = 'checkout_group_uuid';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: true, comment: 'Warehouse ID (P3A)')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('varchar', 24, nullable: true, comment: 'legacy_default|warehouse')]
    public const schema_fields_WAREHOUSE_SOURCE = 'warehouse_source';

    #[Col('text', nullable: true, comment: 'Immutable Offer qty allocation JSON')]
    public const schema_fields_ALLOCATIONS_JSON = 'allocations_json';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Qty minor (requested)')]
    public const schema_fields_QTY_MINOR = 'qty_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Fulfilled qty minor (CAS)')]
    public const schema_fields_FULFILLED_QTY_MINOR = 'fulfilled_qty_minor';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Fulfillment CAS version')]
    public const schema_fields_FULFILLMENT_VERSION = 'fulfillment_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
