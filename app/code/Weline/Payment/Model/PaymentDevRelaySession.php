<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment dev webhook relay session')]
#[Index(name: 'uniq_payment_dev_relay_session_code', columns: ['session_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_dev_relay_session_status', columns: ['status', 'expires_at'])]
class PaymentDevRelaySession extends Model
{
    public const schema_table = 'weline_payment_dev_relay_session';
    public const schema_primary_key = 'session_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CLOSED = 'closed';

    public const ROLE_ONLINE = 'online';
    public const ROLE_LOCAL = 'local';

    public const OUTBOUND_LOCAL_DIRECT = 'local_direct';
    public const OUTBOUND_ONLINE_PROXY = 'online_proxy';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Session ID')]
    public const schema_fields_ID = 'session_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable session code')]
    public const schema_fields_SESSION_CODE = 'session_code';
    #[Col('varchar', 128, nullable: false, comment: 'Relay token hash')]
    public const schema_fields_RELAY_TOKEN_HASH = 'relay_token_hash';
    #[Col('varchar', 16, nullable: false, default: 'online', comment: 'Session role')]
    public const schema_fields_ROLE = 'role';
    #[Col('text', nullable: true, comment: 'Local inbound URL for browser replay')]
    public const schema_fields_LOCAL_INBOUND_URL = 'local_inbound_url';
    #[Col('text', nullable: true, comment: 'Online SSE stream URL display')]
    public const schema_fields_ONLINE_STREAM_URL = 'online_stream_url';
    #[Col('varchar', 32, nullable: false, default: 'local_direct', comment: 'Outbound mode')]
    public const schema_fields_OUTBOUND_MODE = 'outbound_mode';
    #[Col('int', 11, nullable: true, comment: 'Holder backend user id')]
    public const schema_fields_HOLDER_USER_ID = 'holder_user_id';
    #[Col('varchar', 128, nullable: true, comment: 'Holder label')]
    public const schema_fields_HOLDER_LABEL = 'holder_label';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'Session status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Last relay event seq')]
    public const schema_fields_LAST_EVENT_SEQ = 'last_event_seq';
    #[Col('datetime', nullable: false, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['session_id'];
    public array $_index_sort_keys = ['session_code', 'status', 'expires_at'];
}
