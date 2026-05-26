<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment transaction')]
#[Index(name: 'idx_weshop_payment_transaction_order', columns: ['order_id'], comment: 'Order')]
#[Index(name: 'idx_weshop_payment_transaction_reference', columns: ['provider_reference'], comment: 'Provider reference')]
class PaymentTransaction extends Model
{
    public const schema_table = 'weshop_payment_transaction';
    public const schema_primary_key = 'transaction_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Transaction ID')]
    public const schema_fields_ID = 'transaction_id';
    #[Col(type: 'int', nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Order increment ID')]
    public const schema_fields_ORDER_INCREMENT_ID = 'order_increment_id';
    #[Col(type: 'varchar', length: 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'sandbox', comment: 'Environment')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Transaction status')]
    public const schema_fields_STATUS = 'transaction_status';
    #[Col(type: 'varchar', length: 150, nullable: true, comment: 'Provider reference')]
    public const schema_fields_PROVIDER_REFERENCE = 'provider_reference';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Amount')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'varchar', length: 3, nullable: true, comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col(type: 'varchar', length: 120, nullable: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col(type: 'text', nullable: true, comment: 'Action URL')]
    public const schema_fields_ACTION_URL = 'action_url';
    #[Col(type: 'text', nullable: true, comment: 'Provider response JSON')]
    public const schema_fields_PROVIDER_RESPONSE = 'provider_response';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['transaction_id'];
    public array $_index_sort_keys = ['order_id', 'method_code', 'provider_reference', 'transaction_status'];
}
