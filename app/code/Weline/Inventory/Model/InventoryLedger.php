<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable inventory ledger event. Never update qty rows; append only.
 */
#[Table(comment: 'Inventory immutable ledger')]
#[Index(name: 'uk_inv_ledger_event_uuid', columns: ['event_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_inv_ledger_idem', columns: ['idempotency_key', 'event_type'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_ledger_stock', columns: ['website_id', 'store_id', 'offer_id'])]
#[Index(name: 'idx_inv_ledger_warehouse', columns: ['website_id', 'warehouse_id', 'offer_id'])]
class InventoryLedger extends Model
{
    public const schema_table = 'weline_inventory_ledger';
    public const schema_primary_key = 'ledger_id';

    public const TYPE_STOCK_SET = 'stock_set';
    public const TYPE_STOCK_ADJUST = 'stock_adjust';
    public const TYPE_RESERVE = 'reserve';
    public const TYPE_RELEASE = 'release';
    public const TYPE_COMMIT = 'commit';
    public const TYPE_REFUND_RETURN = 'refund_return';
    public const TYPE_WAREHOUSE_ASSIGN = 'warehouse_assign';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_CONFLICT = 'conflict';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Ledger ID')]
    public const schema_fields_ID = 'ledger_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Event UUID')]
    public const schema_fields_EVENT_UUID = 'event_uuid';

    #[Col('varchar', 32, nullable: false, comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('bigint', 20, nullable: false, comment: 'Offer ID')]
    public const schema_fields_OFFER_ID = 'offer_id';

    #[Col('int', 11, nullable: true, comment: 'Original/assigned Warehouse ID (P3A)')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('bigint', 20, nullable: false, comment: 'Signed minor delta')]
    public const schema_fields_QTY_DELTA_MINOR = 'qty_delta_minor';

    #[Col('varchar', 32, nullable: false, default: 'strict', comment: 'Resulting stock strategy')]
    public const schema_fields_STRATEGY = 'strategy';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Resulting oversell allowance minor')]
    public const schema_fields_OVERSELL_ALLOWANCE = 'oversell_allowance';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Resulting preorder allowance minor')]
    public const schema_fields_PREORDER_ALLOWANCE = 'preorder_allowance';

    #[Col('varchar', 36, nullable: true, comment: 'Reservation UUID')]
    public const schema_fields_RESERVATION_UUID = 'reservation_uuid';

    #[Col('varchar', 128, nullable: false, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
