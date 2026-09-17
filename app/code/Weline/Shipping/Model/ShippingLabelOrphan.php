<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Orphan label cancel retries after ledger failure (plan #1/#20). */
#[Table(comment: 'Shipping label orphan cancel queue')]
#[Index(name: 'idx_ship_label_orphan_status', columns: ['status', 'next_attempt_at'])]
class ShippingLabelOrphan extends Model
{
    public const schema_table = 'w_shipping_label_orphan';
    public const schema_primary_key = 'id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEAD = 'dead';
    public const STATUS_DONE = 'done';

    public const MAX_ATTEMPTS = 8;

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 128, nullable: false, default: '')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CARRIER_ID = 'carrier_id';

    #[Col('varchar', 64, nullable: false, default: '')]
    public const schema_fields_SERVICE_CODE = 'service_code';

    #[Col('varchar', 100, nullable: false, default: '')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';

    #[Col('varchar', 64, nullable: false, default: '')]
    public const schema_fields_ORDER_NUMBER = 'order_number';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_PENDING)]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ATTEMPTS = 'attempts';

    #[Col('datetime', nullable: true)]
    public const schema_fields_NEXT_ATTEMPT_AT = 'next_attempt_at';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_LAST_ERROR = 'last_error';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_REASON = 'reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
