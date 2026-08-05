<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Provider command outbox（第一事务写入，事务外消费，MOD-P2F-002）。
 */
#[Table(comment: 'Payment provider command outbox')]
#[Index(name: 'uniq_payment_provider_command_code', columns: ['command_code'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_provider_request_key', columns: ['provider_request_key'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_provider_outbox_status', columns: ['status', 'created_at'])]
#[Index(name: 'idx_payment_provider_outbox_attempt', columns: ['attempt_code'])]
class PaymentProviderCommandOutbox extends Model
{
    public const schema_table = 'weline_payment_provider_command_outbox';
    public const schema_primary_key = 'outbox_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_DEAD = 'dead';

    public const COMMAND_SUBMIT = 'submit';
    public const CLAIM_LEASE_SECONDS = 30;

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Outbox ID')]
    public const schema_fields_ID = 'outbox_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable command code')]
    public const schema_fields_COMMAND_CODE = 'command_code';
    #[Col('varchar', 96, nullable: false, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: false, comment: 'Attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 48, nullable: false, default: 'submit', comment: 'Command type')]
    public const schema_fields_COMMAND_TYPE = 'command_type';
    #[Col('varchar', 160, nullable: false, comment: 'Provider request key attempt:submit:v1')]
    public const schema_fields_PROVIDER_REQUEST_KEY = 'provider_request_key';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Outbox status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Expected attempt version for CAS')]
    public const schema_fields_EXPECTED_ATTEMPT_VERSION = 'expected_attempt_version';
    #[Col('text', nullable: true, comment: 'Command payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('text', nullable: true, comment: 'Provider response snapshot JSON')]
    public const schema_fields_RESPONSE_JSON = 'response_json';
    #[Col('varchar', 96, nullable: true, comment: 'Last error code')]
    public const schema_fields_ERROR_CODE = 'error_code';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Attempt count')]
    public const schema_fields_ATTEMPT_COUNT = 'attempt_count';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Consumer claim CAS token')]
    public const schema_fields_CLAIM_TOKEN = 'claim_token';
    #[Col('datetime', nullable: true, comment: 'Last consumer claim time')]
    public const schema_fields_CLAIMED_AT = 'claimed_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Processed at')]
    public const schema_fields_PROCESSED_AT = 'processed_at';

    public array $_unit_primary_keys = ['outbox_id'];
    public array $_index_sort_keys = ['command_code', 'attempt_code', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public static function buildProviderRequestKey(string $attemptCode, string $commandType = self::COMMAND_SUBMIT, string $version = 'v1'): string
    {
        return strtolower(trim($attemptCode)) . ':' . strtolower(trim($commandType)) . ':' . strtolower(trim($version));
    }
}
