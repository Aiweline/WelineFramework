<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * CheckoutGroup — one purchase attempt spanning 1..N Orders（MOD-P2D-002）.
 */
#[Table(comment: 'Checkout group')]
#[Index(name: 'uk_checkout_group_uuid', columns: ['checkout_group_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_checkout_group_idem', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_checkout_group_scope', columns: ['website_id', 'store_id'])]
class CheckoutGroup extends Model
{
    public const schema_table = 'weline_checkout_group';
    public const schema_primary_key = 'checkout_group_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'checkout_group_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Group UUID')]
    public const schema_fields_CHECKOUT_GROUP_UUID = 'checkout_group_uuid';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 10, nullable: false, default: 'CNY', comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Group status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('varchar', 36, nullable: true, comment: 'Shipping charge owner order UUID')]
    public const schema_fields_SHIPPING_OWNER_ORDER_UUID = 'shipping_owner_order_uuid';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Grand total minor')]
    public const schema_fields_GRAND_TOTAL_MINOR = 'grand_total_minor';

    #[Col('text', nullable: true, comment: 'Money snapshot JSON')]
    public const schema_fields_MONEY_SNAPSHOT_JSON = 'money_snapshot_json';

    #[Col('text', nullable: true, comment: 'Scope snapshot JSON')]
    public const schema_fields_SCOPE_SNAPSHOT_JSON = 'scope_snapshot_json';

    #[Col('text', nullable: true, comment: 'Shipping snapshot JSON')]
    public const schema_fields_SHIPPING_SNAPSHOT_JSON = 'shipping_snapshot_json';

    #[Col('text', nullable: true, comment: 'Tax snapshot JSON')]
    public const schema_fields_TAX_SNAPSHOT_JSON = 'tax_snapshot_json';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
