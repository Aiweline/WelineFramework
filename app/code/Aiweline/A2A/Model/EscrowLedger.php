<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A escrow ledger preview rows')]
#[Index(name: 'idx_a2a_escrow_ledger_order', columns: [self::schema_fields_ORDER_PUBLIC_ID], type: 'KEY', comment: 'Order draft ledger lookup')]
#[Index(name: 'idx_a2a_escrow_ledger_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Escrow ledger status')]
class EscrowLedger extends Model
{
    public const schema_table = 'aiweline_a2a_escrow_ledger';
    public const schema_primary_key = 'escrow_ledger_id';

    public const STATUS_LOCKED = 'locked';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PENDING_RELEASE = 'pending_release';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_PAID = 'paid';
    public const STATUS_REFUND_READY = 'refund_ready';
    public const STATUS_DISPUTE_HOLD = 'dispute_hold';
    public const STATUS_WALLET_PENDING = 'wallet_pending';
    public const STATUS_REWORK_HOLD = 'rework_hold';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Escrow ledger ID')]
    public const schema_fields_ID = 'escrow_ledger_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public order draft ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Ledger entry type')]
    public const schema_fields_ENTRY_TYPE = 'entry_type';

    #[Col(type: 'varchar', length: 120, nullable: false, comment: 'Ledger row label')]
    public const schema_fields_LABEL = 'label';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Ledger row amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Ledger status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Ledger metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }

        $this->setData(self::schema_fields_CURRENCY_CODE, \strtoupper((string)($this->getData(self::schema_fields_CURRENCY_CODE) ?: 'USD')));
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
