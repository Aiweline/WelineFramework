<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment dev webhook relay event')]
#[Index(name: 'uniq_payment_dev_relay_event_code', columns: ['event_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_dev_relay_event_session_seq', columns: ['session_code', 'seq'])]
class PaymentDevRelayEvent extends Model
{
    public const schema_table = 'weline_payment_dev_relay_event';
    public const schema_primary_key = 'event_id';

    public const RELAY_STATUS_PENDING = 'pending';
    public const RELAY_STATUS_DELIVERED = 'delivered';
    public const RELAY_STATUS_FAILED = 'failed';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Event ID')]
    public const schema_fields_ID = 'event_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable event code')]
    public const schema_fields_EVENT_CODE = 'event_code';
    #[Col('varchar', 96, nullable: false, comment: 'Session code')]
    public const schema_fields_SESSION_CODE = 'session_code';
    #[Col('int', 11, nullable: false, comment: 'Monotonic seq per session')]
    public const schema_fields_SEQ = 'seq';
    #[Col('varchar', 96, nullable: false, comment: 'Source inbox code')]
    public const schema_fields_INBOX_CODE = 'inbox_code';
    #[Col('varchar', 96, nullable: false, comment: 'Endpoint code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';
    #[Col('varchar', 96, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 160, nullable: false, comment: 'Provider event id')]
    public const schema_fields_PROVIDER_EVENT_ID = 'provider_event_id';
    #[Col('varchar', 64, nullable: true, comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Relay delivery status')]
    public const schema_fields_RELAY_STATUS = 'relay_status';
    #[Col('varchar', 255, nullable: true, comment: 'Relay error message')]
    public const schema_fields_RELAY_ERROR = 'relay_error';
    #[Col('datetime', nullable: true, comment: 'Relayed at')]
    public const schema_fields_RELAYED_AT = 'relayed_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['event_id'];
    public array $_index_sort_keys = ['session_code', 'seq', 'relay_status'];
}
