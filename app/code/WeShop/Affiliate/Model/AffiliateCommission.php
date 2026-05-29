<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop affiliate commission ledger table')]
#[Index(name: 'uk_order_item_affiliate', columns: [self::schema_fields_ORDER_ID, self::schema_fields_ORDER_ITEM_ID, self::schema_fields_AFFILIATE_ID], type: 'UNIQUE', comment: 'One commission row per order item and affiliate')]
#[Index(name: 'idx_affiliate_status', columns: [self::schema_fields_AFFILIATE_ID, self::schema_fields_STATUS], type: 'KEY', comment: 'Affiliate commission status lookup')]
#[Index(name: 'idx_share_id', columns: [self::schema_fields_SHARE_ID], type: 'KEY', comment: 'Share commission lookup')]
#[Index(name: 'idx_order_id', columns: [self::schema_fields_ORDER_ID], type: 'KEY', comment: 'Order commission lookup')]
#[Index(name: 'idx_product_id', columns: [self::schema_fields_PRODUCT_ID], type: 'KEY', comment: 'Product commission lookup')]
class AffiliateCommission extends Model
{
    public const schema_table = 'weshop_affiliate_commission';
    public const schema_primary_key = 'commission_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Commission ID')]
    public const schema_fields_ID = 'commission_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_AFFILIATE_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, comment: 'Share ID')]
    public const schema_fields_SHARE_ID = 'share_id';

    #[Col(type: 'int', nullable: false, comment: 'Attribution ID')]
    public const schema_fields_ATTRIBUTION_ID = 'attribution_id';

    #[Col(type: 'int', nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Order item ID')]
    public const schema_fields_ORDER_ITEM_ID = 'order_item_id';

    #[Col(type: 'int', nullable: false, comment: 'Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Buyer customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Commission base amount')]
    public const schema_fields_BASE_AMOUNT = 'base_amount';

    #[Col(type: 'decimal', length: '5,2', nullable: false, default: '0.00', comment: 'Commission rate')]
    public const schema_fields_COMMISSION_RATE = 'commission_rate';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Commission amount')]
    public const schema_fields_COMMISSION_AMOUNT = 'commission_amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: '', comment: 'Checkout currency code for this commission')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 24, nullable: false, default: 'pending', comment: 'Commission status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Status reason')]
    public const schema_fields_REASON = 'reason';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_AFFILIATE_ID,
        self::schema_fields_SHARE_ID,
        self::schema_fields_ORDER_ID,
        self::schema_fields_PRODUCT_ID,
        self::schema_fields_CURRENCY_CODE,
        self::schema_fields_STATUS,
        self::schema_fields_CREATED_AT,
    ];
}
