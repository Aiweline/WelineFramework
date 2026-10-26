<?php

declare(strict_types=1);

namespace Weline\Points\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Points used as checkout discount. Reservations are committed on payment and
 * returned on refund/cancel.
 */
#[Table(comment: 'Points checkout discount apply record')]
#[Index(name: 'uk_points_discount_order', columns: ['order_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_points_discount_owner', columns: ['customer_id', 'website_id', 'namespace'])]
class DiscountApplyRecord extends Model
{
    public const schema_table = 'weline_points_discount_apply';
    public const schema_primary_key = 'apply_id';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_RETURNED = 'returned';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Apply ID')]
    public const schema_fields_ID = 'apply_id';

    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('int', 11, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'live', comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('varchar', 10, nullable: false, default: '', comment: 'Checkout currency')]
    public const schema_fields_CHECKOUT_CURRENCY = 'checkout_currency';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Points reserved')]
    public const schema_fields_POINTS = 'points';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Discount in checkout currency minor units')]
    public const schema_fields_APPLY_CHECKOUT_MINOR = 'apply_checkout_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Discount in base currency minor units')]
    public const schema_fields_APPLY_BASE_MINOR = 'apply_base_minor';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'CustomerAsset reservation ID')]
    public const schema_fields_RESERVATION_ID = 'reservation_id';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Returned points so far')]
    public const schema_fields_RETURNED_POINTS = 'returned_points';

    #[Col('varchar', 20, nullable: false, default: self::STATUS_RESERVED, comment: 'Apply status')]
    public const schema_fields_STATUS = 'status';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Committed points')]
    public const schema_fields_COMMITTED_POINTS = 'committed_points';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
