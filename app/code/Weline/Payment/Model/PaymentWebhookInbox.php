<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Immutable webhook inbox — unique (endpoint_code, provider_event_id). */
#[Table(comment: 'Payment webhook inbox')]
#[Index(name: 'uniq_payment_webhook_inbox_event', columns: ['endpoint_code', 'provider_event_id'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_webhook_inbox_code', columns: ['inbox_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_webhook_inbox_status', columns: ['status', 'received_at'])]
class PaymentWebhookInbox extends Model
{
    public const schema_table = 'weline_payment_webhook_inbox';
    public const schema_primary_key = 'inbox_id';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_CONFLICT = 'conflict';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Inbox ID')]
    public const schema_fields_ID = 'inbox_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable inbox code')]
    public const schema_fields_INBOX_CODE = 'inbox_code';
    #[Col('varchar', 96, nullable: false, comment: 'Endpoint code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';
    #[Col('varchar', 160, nullable: false, comment: 'Provider event ID')]
    public const schema_fields_PROVIDER_EVENT_ID = 'provider_event_id';
    #[Col('varchar', 96, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 128, nullable: false, comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'Environment')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, default: '1', comment: 'Webhook schema version')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';
    #[Col('varchar', 32, nullable: false, comment: 'Matched verification secret version')]
    public const schema_fields_VERIFICATION_SECRET_VERSION = 'verification_secret_version';
    #[Col('varchar', 128, nullable: false, comment: 'Payload hash')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';
    #[Col('text', nullable: false, comment: 'Encrypted raw payload')]
    public const schema_fields_ENCRYPTED_RAW_PAYLOAD = 'encrypted_raw_payload';
    #[Col('text', nullable: true, comment: 'Encrypted headers JSON')]
    public const schema_fields_ENCRYPTED_HEADERS = 'encrypted_headers';
    #[Col('varchar', 255, nullable: true, comment: 'Encrypted signature ref')]
    public const schema_fields_ENCRYPTED_SIGNATURE = 'encrypted_signature';
    #[Col('varchar', 32, nullable: false, default: 'received', comment: 'Inbox status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Consumer state version')]
    public const schema_fields_CONSUMER_VERSION = 'consumer_version';
    #[Col('varchar', 96, nullable: true, comment: 'Auditable ignore reason')]
    public const schema_fields_IGNORE_REASON = 'ignore_reason';
    #[Col('varchar', 96, nullable: true, comment: 'Intent code hint')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Attempt code hint')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 64, nullable: true, comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col('varchar', 64, nullable: true, comment: 'Suggested status transition')]
    public const schema_fields_STATUS_TRANSITION = 'status_transition';
    #[Col('datetime', nullable: false, comment: 'Received at')]
    public const schema_fields_RECEIVED_AT = 'received_at';
    #[Col('datetime', nullable: true, comment: 'Applied at')]
    public const schema_fields_APPLIED_AT = 'applied_at';
    #[Col('datetime', nullable: true, comment: 'Retain until')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['inbox_id'];
    public array $_index_sort_keys = ['inbox_code', 'endpoint_code', 'provider_event_id', 'status', 'received_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
