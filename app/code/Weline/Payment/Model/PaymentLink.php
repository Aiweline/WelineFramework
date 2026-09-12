<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Shareable payment / selection short links')]
#[Index(name: 'uniq_payment_link_code', columns: ['payment_link_code'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_link_kind_token', columns: ['kind', 'token'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_link_status_expires', columns: ['status', 'expires_at'])]
class PaymentLink extends Model
{
    public const schema_table = 'weline_payment_link';
    public const schema_primary_key = 'link_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Link ID')]
    public const schema_fields_ID = 'link_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable payment_link_code')]
    public const schema_fields_PAYMENT_LINK_CODE = 'payment_link_code';
    #[Col('varchar', 64, nullable: false, comment: 'Public short token')]
    public const schema_fields_TOKEN = 'token';
    #[Col('varchar', 32, nullable: false, comment: 'help_pay|selection_share|quick_pay_self')]
    public const schema_fields_KIND = 'kind';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'active|revoked|expired|consumed')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 64, nullable: true, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: true, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('bigint', 20, nullable: true, comment: 'Owner customer id')]
    public const schema_fields_OWNER_CUSTOMER_ID = 'owner_customer_id';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Amount minor')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('varchar', 3, nullable: false, default: 'USD', comment: 'Currency')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Shipping locked')]
    public const schema_fields_SHIPPING_LOCKED = 'shipping_locked';
    #[Col('text', nullable: true, comment: 'Shipping snapshot JSON (server only)')]
    public const schema_fields_SHIPPING_SNAPSHOT = 'shipping_snapshot';
    #[Col('text', nullable: true, comment: 'Selection snapshot JSON')]
    public const schema_fields_SELECTION_SNAPSHOT = 'selection_snapshot';
    #[Col('text', nullable: true, comment: 'Meta JSON')]
    public const schema_fields_META = 'meta';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Expires unix')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('bigint', 20, nullable: true, comment: 'Revoked unix')]
    public const schema_fields_REVOKED_AT = 'revoked_at';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Created unix')]
    public const schema_fields_CREATED_AT = 'created_at';
}
