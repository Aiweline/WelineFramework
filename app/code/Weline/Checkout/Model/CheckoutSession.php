<?php

declare(strict_types=1);

namespace Weline\Checkout\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Persistent checkout freeze session（quote token / versions）.
 * Memory harness uses CheckoutSessionStore; this Model is additive for setup:upgrade.
 */
#[Table(comment: 'Checkout freeze session')]
#[Index(name: 'uk_checkout_session_token', columns: ['quote_token'], type: 'UNIQUE')]
#[Index(name: 'idx_checkout_session_state', columns: ['state', 'expires_at'])]
class CheckoutSession extends Model
{
    public const STATE_QUOTED = 'quoted';
    public const STATE_SUBMITTING = 'submitting';
    public const STATE_SUBMITTED = 'submitted';

    public const schema_table = 'weline_checkout_session';
    public const schema_primary_key = 'session_id';
    public string $_primary_key = 'session_id';
    public array $_unit_primary_keys = ['session_id'];

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'session_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Quote token')]
    public const schema_fields_QUOTE_TOKEN = 'quote_token';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'CNY', comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '1', comment: 'Config version')]
    public const schema_fields_CONFIG_VERSION = 'config_version';

    #[Col(type: 'varchar', length: 24, nullable: false, default: self::STATE_QUOTED, comment: 'Session state')]
    public const schema_fields_STATE = 'state';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Submit idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col(type: 'text', nullable: true, comment: 'Submitted result JSON')]
    public const schema_fields_SUBMITTED_RESULT_JSON = 'submitted_result_json';

    #[Col(type: 'text', nullable: true, comment: 'Frozen JSON payload')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Expires')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
