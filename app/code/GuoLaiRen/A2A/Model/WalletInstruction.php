<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A dry-run wallet adapter instruction')]
#[Index(name: 'uk_a2a_wallet_instruction_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public wallet instruction ID')]
#[Index(name: 'idx_a2a_wallet_instruction_order', columns: [self::schema_fields_ORDER_PUBLIC_ID], type: 'KEY', comment: 'Order wallet instruction lookup')]
#[Index(name: 'idx_a2a_wallet_instruction_ruling', columns: [self::schema_fields_RULING_PUBLIC_ID], type: 'KEY', comment: 'Ruling wallet instruction lookup')]
#[Index(name: 'idx_a2a_wallet_instruction_status', columns: [self::schema_fields_ADAPTER_STATUS], type: 'KEY', comment: 'Wallet adapter status')]
#[Index(name: 'idx_a2a_wallet_instruction_idempotency', columns: [self::schema_fields_IDEMPOTENCY_KEY], type: 'KEY', comment: 'Wallet adapter idempotency lookup')]
class WalletInstruction extends Model
{
    public const schema_table = 'guolairen_a2a_wallet_instruction';
    public const schema_primary_key = 'wallet_instruction_id';

    public const STATUS_DRY_RUN_QUEUED = 'dry_run_queued';
    public const STATUS_BLOCKED_HOLD = 'blocked_hold';
    public const STATUS_ADAPTER_PENDING = 'adapter_pending';
    public const STATUS_ADAPTER_CONFIRMED = 'adapter_confirmed';
    public const STATUS_ADAPTER_FAILED = 'adapter_failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Wallet instruction ID')]
    public const schema_fields_ID = 'wallet_instruction_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public wallet instruction ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Arbitration ruling public ID')]
    public const schema_fields_RULING_PUBLIC_ID = 'ruling_public_id';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Ledger entry type')]
    public const schema_fields_LEDGER_ENTRY_TYPE = 'ledger_entry_type';

    #[Col(type: 'varchar', length: 48, nullable: false, comment: 'Wallet instruction type')]
    public const schema_fields_INSTRUCTION_TYPE = 'instruction_type';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Instruction amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 40, nullable: false, default: 'prototype_wallet', comment: 'Wallet adapter code')]
    public const schema_fields_ADAPTER_CODE = 'adapter_code';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Wallet adapter status')]
    public const schema_fields_ADAPTER_STATUS = 'adapter_status';

    #[Col(type: 'varchar', length: 96, nullable: false, default: '', comment: 'Adapter idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col(type: 'varchar', length: 96, nullable: false, default: '', comment: 'External wallet reference')]
    public const schema_fields_EXTERNAL_REFERENCE = 'external_reference';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Adapter failure reason')]
    public const schema_fields_FAILURE_REASON = 'failure_reason';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Adapter retry count')]
    public const schema_fields_RETRY_COUNT = 'retry_count';

    #[Col(type: 'longtext', nullable: true, comment: 'Wallet instruction metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Instruction queued at')]
    public const schema_fields_QUEUED_AT = 'queued_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Adapter executed at')]
    public const schema_fields_EXECUTED_AT = 'executed_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Adapter reconciled at')]
    public const schema_fields_RECONCILED_AT = 'reconciled_at';

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
