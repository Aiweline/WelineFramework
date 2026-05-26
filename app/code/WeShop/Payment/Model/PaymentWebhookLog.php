<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment webhook log')]
#[Index(name: 'idx_weshop_payment_webhook_method', columns: ['method_code'], comment: 'Method')]
#[Index(name: 'idx_weshop_payment_webhook_event', columns: ['event_id'], comment: 'Event ID')]
class PaymentWebhookLog extends Model
{
    public const schema_table = 'weshop_payment_webhook_log';
    public const schema_primary_key = 'webhook_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Webhook log ID')]
    public const schema_fields_ID = 'webhook_id';
    #[Col(type: 'varchar', length: 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 150, nullable: true, comment: 'Provider reference')]
    public const schema_fields_PROVIDER_REFERENCE = 'provider_reference';
    #[Col(type: 'varchar', length: 150, nullable: true, comment: 'Provider event ID')]
    public const schema_fields_EVENT_ID = 'event_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'received', comment: 'Webhook status')]
    public const schema_fields_STATUS = 'webhook_status';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Signature valid')]
    public const schema_fields_SIGNATURE_VALID = 'signature_valid';
    #[Col(type: 'text', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD = 'payload';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['webhook_id'];
    public array $_index_sort_keys = ['method_code', 'event_id', 'webhook_status'];
}
