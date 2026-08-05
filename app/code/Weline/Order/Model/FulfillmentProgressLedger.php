<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Immutable partial-fulfillment progress event. */
#[Table(comment: 'Order fulfillment progress ledger')]
#[Index(name: 'uk_fulfillment_progress_event', columns: ['event_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_fulfillment_progress_idem', columns: ['idempotency_key', 'event_type'], type: 'UNIQUE')]
#[Index(name: 'idx_fulfillment_progress_unit', columns: ['fulfillment_unit_uuid', 'new_version'])]
class FulfillmentProgressLedger extends Model
{
    public const schema_table = 'weline_fulfillment_progress_ledger';
    public const schema_primary_key = 'progress_id';

    public const TYPE_PARTIAL_SHIP = 'partial_ship';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Progress ID')]
    public const schema_fields_ID = 'progress_id';
    #[Col('varchar', 36, nullable: false, comment: 'Event UUID')]
    public const schema_fields_EVENT_UUID = 'event_uuid';
    #[Col('varchar', 32, nullable: false, comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col('varchar', 36, nullable: false, comment: 'Fulfillment unit UUID')]
    public const schema_fields_UNIT_UUID = 'fulfillment_unit_uuid';
    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('int', 11, nullable: false, comment: 'Original Warehouse ID')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';
    #[Col('bigint', 20, nullable: false, comment: 'Shipped qty minor')]
    public const schema_fields_QTY_MINOR = 'qty_minor';
    #[Col('int', 11, nullable: false, comment: 'Expected CAS version')]
    public const schema_fields_EXPECTED_VERSION = 'expected_version';
    #[Col('int', 11, nullable: false, comment: 'Committed CAS version')]
    public const schema_fields_NEW_VERSION = 'new_version';
    #[Col('varchar', 128, nullable: false, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('char', 64, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
