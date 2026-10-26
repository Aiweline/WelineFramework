<?php

declare(strict_types=1);

namespace Weline\Points\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable earning fact. One row per paid-order earning event.
 */
#[Table(comment: 'Points earning record')]
#[Index(name: 'uk_points_earn_event', columns: ['event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_points_earn_order', columns: ['order_id', 'customer_id'])]
#[Index(name: 'idx_points_earn_owner', columns: ['customer_id', 'website_id', 'namespace'])]
class EarnRecord extends Model
{
    public const schema_table = 'weline_points_earn_record';
    public const schema_primary_key = 'record_id';

    public const STATUS_AWARDED = 'awarded';
    public const STATUS_PARTIAL_CLAWBACK = 'partial_clawback';
    public const STATUS_CLAWED_BACK = 'clawed_back';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Record ID')]
    public const schema_fields_ID = 'record_id';

    #[Col('varchar', 191, nullable: false, comment: 'Globally unique earning event ID')]
    public const schema_fields_EVENT_ID = 'event_id';

    #[Col('int', 11, nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';

    #[Col('varchar', 36, nullable: false, default: '', comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Payment identity')]
    public const schema_fields_PAYMENT_KEY = 'payment_key';

    #[Col('int', 11, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'live', comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('varchar', 10, nullable: false, default: 'CNY', comment: 'Currency code')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('int', 11, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Cash paid in minor units')]
    public const schema_fields_CASH_MINOR = 'cash_minor';

    #[Col('varchar', 10, nullable: false, default: '', comment: 'Checkout currency')]
    public const schema_fields_CHECKOUT_CURRENCY = 'checkout_currency';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Cash paid in checkout currency minor units')]
    public const schema_fields_CHECKOUT_CASH_MINOR = 'checkout_cash_minor';

    #[Col('varchar', 10, nullable: false, default: '', comment: 'Website base currency')]
    public const schema_fields_BASE_CURRENCY = 'base_currency';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Cash paid in base currency minor units')]
    public const schema_fields_CASH_BASE_MINOR = 'cash_base_minor';

    #[Col('text', nullable: false, default: '[]', comment: 'Per-line earning snapshot JSON')]
    public const schema_fields_ITEMS_SNAPSHOT_JSON = 'items_snapshot_json';

    #[Col('decimal', '10,4', nullable: false, default: '0.0000', comment: 'Points per major currency unit')]
    public const schema_fields_POINTS_RATE = 'points_rate';

    #[Col('decimal', '6,3', nullable: false, default: '1.000', comment: 'VIP multiplier snapshot')]
    public const schema_fields_POINTS_MULTIPLIER = 'points_multiplier';

    #[Col('decimal', '10,4', nullable: false, default: '0.0000', comment: 'Growth per major currency unit')]
    public const schema_fields_GROWTH_RATE = 'growth_rate';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Points awarded')]
    public const schema_fields_POINTS_AWARDED = 'points_awarded';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Growth awarded')]
    public const schema_fields_GROWTH_AWARDED = 'growth_awarded';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Points clawed back')]
    public const schema_fields_POINTS_CLAWED_BACK = 'points_clawed_back';

    #[Col('varchar', 20, nullable: false, default: self::STATUS_AWARDED, comment: 'Earning status')]
    public const schema_fields_STATUS = 'status';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
