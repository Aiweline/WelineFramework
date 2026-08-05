<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Reservation row. Lease renew/CAS fields used by P2B-002.
 */
#[Table(comment: 'Inventory reservation')]
#[Index(name: 'uk_inv_reservation_uuid', columns: ['reservation_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_inv_reservation_idem', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_reservation_state', columns: ['state'])]
#[Index(name: 'idx_inv_reservation_stock', columns: ['website_id', 'store_id', 'offer_id'])]
#[Index(name: 'idx_inv_reservation_expiry', columns: ['state', 'lease_expires_at', 'reservation_id'])]
class Reservation extends Model
{
    public const schema_table = 'weline_inventory_reservation';
    public const schema_primary_key = 'reservation_id';

    public const STATE_RESERVED = 'reserved';
    public const STATE_COMMITTED = 'committed';
    public const STATE_RELEASED = 'released';
    public const STATE_EXPIRED = 'expired';
    public const STATE_CONFLICT = 'conflict';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'reservation_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Reservation UUID')]
    public const schema_fields_RESERVATION_UUID = 'reservation_uuid';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('bigint', 20, nullable: false, comment: 'Offer ID')]
    public const schema_fields_OFFER_ID = 'offer_id';

    #[Col('bigint', 20, nullable: false, comment: 'Quantity minor')]
    public const schema_fields_QUANTITY_MINOR = 'quantity_minor';

    #[Col('varchar', 32, nullable: false, default: self::STATE_RESERVED, comment: 'State')]
    public const schema_fields_STATE = 'state';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('int', 11, nullable: true, comment: 'Warehouse ID (P3A; null=Store logical)')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('varchar', 64, nullable: true, comment: 'Lease owner attempt code')]
    public const schema_fields_LEASE_OWNER_ATTEMPT_CODE = 'lease_owner_attempt_code';

    #[Col('datetime', nullable: true, comment: 'Attempt started at UTC')]
    public const schema_fields_LEASE_STARTED_AT = 'lease_started_at';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Queued Order does not renew')]
    public const schema_fields_QUEUED_ORDER = 'queued_order';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Lease version')]
    public const schema_fields_LEASE_VERSION = 'lease_version';

    #[Col('datetime', nullable: true, comment: 'Lease expires at')]
    public const schema_fields_LEASE_EXPIRES_AT = 'lease_expires_at';

    #[Col('datetime', nullable: true, comment: 'Lease max expires at')]
    public const schema_fields_LEASE_MAX_EXPIRES_AT = 'lease_max_expires_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
