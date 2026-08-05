<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Deterministic payment effect outbox（consumer writes in P2F-004）. */
#[Table(comment: 'Payment effect outbox')]
#[Index(name: 'uniq_payment_outbox_effect_key', columns: ['effect_key'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_outbox_code', columns: ['outbox_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_outbox_status', columns: ['status', 'created_at'])]
class PaymentOutbox extends Model
{
    public const schema_table = 'weline_payment_outbox';
    public const schema_primary_key = 'outbox_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_DEAD = 'dead';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Outbox ID')]
    public const schema_fields_ID = 'outbox_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable outbox code')]
    public const schema_fields_OUTBOX_CODE = 'outbox_code';
    #[Col('varchar', 191, nullable: false, comment: 'Deterministic effect key')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col('varchar', 96, nullable: true, comment: 'Linked inbox code')]
    public const schema_fields_INBOX_CODE = 'inbox_code';
    #[Col('varchar', 96, nullable: true, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 48, nullable: false, comment: 'Effect type')]
    public const schema_fields_EFFECT_TYPE = 'effect_type';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Outbox status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Processed at')]
    public const schema_fields_PROCESSED_AT = 'processed_at';

    public array $_unit_primary_keys = ['outbox_id'];
    public array $_index_sort_keys = ['outbox_code', 'effect_key', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
