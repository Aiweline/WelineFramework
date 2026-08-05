<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment webhook endpoint')]
#[Index(name: 'uniq_payment_webhook_endpoint_code', columns: ['endpoint_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_webhook_endpoint_provider', columns: ['provider_code', 'method_code', 'environment'])]
#[Index(name: 'idx_payment_webhook_endpoint_status', columns: ['status'])]
class PaymentWebhookEndpoint extends Model
{
    public const schema_table = 'weline_payment_webhook_endpoint';
    public const schema_primary_key = 'endpoint_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_TOMBSTONE = 'tombstone';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Endpoint ID')]
    public const schema_fields_ID = 'endpoint_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable endpoint code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';
    #[Col('varchar', 96, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 128, nullable: false, default: 'default', comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'Endpoint status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 32, nullable: false, default: 'v1', comment: 'Active secret version')]
    public const schema_fields_ACTIVE_SECRET_VERSION = 'active_secret_version';
    #[Col('varchar', 64, nullable: false, default: '1', comment: 'Context version')]
    public const schema_fields_CONTEXT_VERSION = 'context_version';
    #[Col('text', nullable: true, comment: 'Scope / store_mode snapshot JSON')]
    public const schema_fields_SCOPE_SNAPSHOT_JSON = 'scope_snapshot_json';
    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Allow new capture')]
    public const schema_fields_ALLOW_NEW_CAPTURE = 'allow_new_capture';
    #[Col('datetime', nullable: true, comment: 'Retain until')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['endpoint_id'];
    public array $_index_sort_keys = ['endpoint_code', 'provider_code', 'method_code', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
