<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop affiliate attribution touch log table')]
#[Index(name: 'idx_share_id', columns: [self::schema_fields_SHARE_ID], type: 'KEY', comment: 'Share touch lookup')]
#[Index(name: 'idx_affiliate_event', columns: [self::schema_fields_AFFILIATE_ID, self::schema_fields_EVENT_TYPE], type: 'KEY', comment: 'Affiliate event lookup')]
#[Index(name: 'idx_product_id', columns: [self::schema_fields_PRODUCT_ID], type: 'KEY', comment: 'Product lookup')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'KEY', comment: 'Customer lookup')]
#[Index(name: 'idx_order_id', columns: [self::schema_fields_ORDER_ID], type: 'KEY', comment: 'Order lookup')]
#[Index(name: 'idx_visitor_key', columns: [self::schema_fields_VISITOR_KEY], type: 'KEY', comment: 'Visitor attribution lookup')]
#[Index(name: 'idx_idempotency_key', columns: [self::schema_fields_IDEMPOTENCY_KEY], type: 'KEY', comment: 'Touch idempotency key')]
class AffiliateTouch extends Model
{
    public const schema_table = 'weshop_affiliate_touch';
    public const schema_primary_key = 'touch_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Touch ID')]
    public const schema_fields_ID = 'touch_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Share ID')]
    public const schema_fields_SHARE_ID = 'share_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Affiliate ID')]
    public const schema_fields_AFFILIATE_ID = 'affiliate_id';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Visitor key')]
    public const schema_fields_VISITOR_KEY = 'visitor_key';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Event value')]
    public const schema_fields_VALUE = 'value';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: 'Marketing channel')]
    public const schema_fields_CHANNEL = 'channel';

    #[Col(type: 'text', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_AFFILIATE_ID,
        self::schema_fields_EVENT_TYPE,
        self::schema_fields_PRODUCT_ID,
        self::schema_fields_CUSTOMER_ID,
        self::schema_fields_ORDER_ID,
        self::schema_fields_CREATED_AT,
    ];
}
