<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop affiliate active attribution table')]
#[Index(name: 'idx_share_id', columns: [self::schema_fields_SHARE_ID], type: 'KEY', comment: 'Share attribution lookup')]
#[Index(name: 'idx_affiliate_id', columns: [self::schema_fields_AFFILIATE_ID], type: 'KEY', comment: 'Affiliate lookup')]
#[Index(name: 'idx_customer_status', columns: [self::schema_fields_CUSTOMER_ID, self::schema_fields_STATUS], type: 'KEY', comment: 'Customer active attribution lookup')]
#[Index(name: 'idx_visitor_status', columns: [self::schema_fields_VISITOR_KEY, self::schema_fields_STATUS], type: 'KEY', comment: 'Visitor active attribution lookup')]
#[Index(name: 'idx_expires_at', columns: [self::schema_fields_EXPIRES_AT], type: 'KEY', comment: 'Attribution expiry lookup')]
class AffiliateAttribution extends Model
{
    public const schema_table = 'weshop_affiliate_attribution';
    public const schema_primary_key = 'attribution_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Attribution ID')]
    public const schema_fields_ID = 'attribution_id';

    #[Col(type: 'int', nullable: false, comment: 'Share ID')]
    public const schema_fields_SHARE_ID = 'share_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_AFFILIATE_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Visitor key')]
    public const schema_fields_VISITOR_KEY = 'visitor_key';

    #[Col(type: 'int', nullable: false, comment: 'Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: 'Attribution status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: false, comment: 'First touch at')]
    public const schema_fields_FIRST_TOUCH_AT = 'first_touch_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Last touch at')]
    public const schema_fields_LAST_TOUCH_AT = 'last_touch_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_AFFILIATE_ID,
        self::schema_fields_CUSTOMER_ID,
        self::schema_fields_VISITOR_KEY,
        self::schema_fields_PRODUCT_ID,
        self::schema_fields_STATUS,
        self::schema_fields_EXPIRES_AT,
    ];
}
