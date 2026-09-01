<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment dev webhook relay command')]
#[Index(name: 'uniq_payment_dev_relay_command_code', columns: ['command_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_dev_relay_command_session', columns: ['session_code', 'status'])]
class PaymentDevRelayCommand extends Model
{
    public const schema_table = 'weline_payment_dev_relay_command';
    public const schema_primary_key = 'command_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Command ID')]
    public const schema_fields_ID = 'command_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable command code')]
    public const schema_fields_COMMAND_CODE = 'command_code';
    #[Col('varchar', 96, nullable: false, comment: 'Session code')]
    public const schema_fields_SESSION_CODE = 'session_code';
    #[Col('varchar', 64, nullable: false, comment: 'Command action')]
    public const schema_fields_ACTION = 'action';
    #[Col('text', nullable: false, comment: 'Request JSON')]
    public const schema_fields_REQUEST_JSON = 'request_json';
    #[Col('text', nullable: true, comment: 'Response JSON')]
    public const schema_fields_RESPONSE_JSON = 'response_json';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Command status')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['command_id'];
    public array $_index_sort_keys = ['command_code', 'session_code', 'status'];
}
