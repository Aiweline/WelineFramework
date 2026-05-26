<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop affiliate product share link table')]
#[Index(name: 'uk_share_code', columns: [self::schema_fields_SHARE_CODE], type: 'UNIQUE', comment: 'Unique affiliate share code')]
#[Index(name: 'idx_affiliate_product_channel', columns: [self::schema_fields_AFFILIATE_ID, self::schema_fields_PRODUCT_ID, self::schema_fields_CHANNEL], type: 'KEY', comment: 'Affiliate product channel lookup')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'KEY', comment: 'Customer lookup')]
#[Index(name: 'idx_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Share status lookup')]
class AffiliateShare extends Model
{
    public const schema_table = 'weshop_affiliate_share';
    public const schema_primary_key = 'share_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Share ID')]
    public const schema_fields_ID = 'share_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_AFFILIATE_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'int', nullable: false, comment: 'Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: 'Share channel')]
    public const schema_fields_CHANNEL = 'channel';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Share code')]
    public const schema_fields_SHARE_CODE = 'share_code';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Target route path')]
    public const schema_fields_TARGET_PATH = 'target_path';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: 'Share status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Outbound share count')]
    public const schema_fields_OUTBOUND_COUNT = 'outbound_count';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Click count')]
    public const schema_fields_CLICK_COUNT = 'click_count';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Attributed order count')]
    public const schema_fields_ORDER_COUNT = 'order_count';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_AFFILIATE_ID,
        self::schema_fields_PRODUCT_ID,
        self::schema_fields_CHANNEL,
        self::schema_fields_STATUS,
        self::schema_fields_CREATED_AT,
    ];
}
