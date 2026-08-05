<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Secret refs only — never store plaintext webhook secrets. */
#[Table(comment: 'Payment webhook secret versions')]
#[Index(name: 'uniq_payment_webhook_secret_version', columns: ['endpoint_code', 'secret_version'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_webhook_secret_status', columns: ['status'])]
class PaymentWebhookSecret extends Model
{
    public const schema_table = 'weline_payment_webhook_secret';
    public const schema_primary_key = 'secret_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRACE = 'grace';
    public const STATUS_RETIRED = 'retired';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Secret ID')]
    public const schema_fields_ID = 'secret_id';
    #[Col('varchar', 96, nullable: false, comment: 'Endpoint code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';
    #[Col('varchar', 32, nullable: false, comment: 'Secret version')]
    public const schema_fields_SECRET_VERSION = 'secret_version';
    #[Col('varchar', 191, nullable: false, comment: 'Opaque secret_ref (no plaintext)')]
    public const schema_fields_SECRET_REF = 'secret_ref';
    #[Col('varchar', 16, nullable: false, default: 'active', comment: 'active|grace|retired')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: 'Valid from')]
    public const schema_fields_VALID_FROM = 'valid_from';
    #[Col('datetime', nullable: true, comment: 'Valid until')]
    public const schema_fields_VALID_UNTIL = 'valid_until';
    #[Col('datetime', nullable: true, comment: 'Retain until')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['secret_id'];
    public array $_index_sort_keys = ['endpoint_code', 'secret_version', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
