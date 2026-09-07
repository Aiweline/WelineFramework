<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product storefront quote requests')]
#[Index(name: 'uk_product_quote_request_idempotency', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_product_quote_request_status_created', columns: ['status', 'created_at'])]
#[Index(name: 'idx_product_quote_request_product', columns: ['product_id', 'created_at'])]
#[Index(name: 'idx_product_quote_request_customer', columns: ['customer_id', 'admin_reply_at'])]
final class ProductQuoteRequest extends Model
{
    public const schema_table = 'weline_product_quote_request';
    public const schema_primary_key = 'quote_request_id';

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PROCESSING,
        self::STATUS_REPLIED,
        self::STATUS_PROCESSED,
        self::STATUS_CANCELLED,
    ];

    public const MENU_SIGNAL_CODE = 'product.quotes';
    public const ACCOUNT_SECTION = 'product-quotes';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '询价单 ID')]
    public const schema_fields_ID = 'quote_request_id';

    #[Col('bigint', 20, nullable: false, default: 0, comment: '商品 ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('varchar', 36, nullable: false, default: '', comment: 'Offer UUID')]
    public const schema_fields_GLOBAL_OFFER_UUID = 'global_offer_uuid';

    #[Col('text', nullable: true, comment: '规格选择 JSON')]
    public const schema_fields_SELECTION_JSON = 'selection_json';

    #[Col('bigint', 20, nullable: false, default: 0, comment: '参考价（分）')]
    public const schema_fields_REFERENCE_PRICE_MINOR = 'reference_price_minor';

    #[Col('varchar', 8, nullable: false, default: 'CNY', comment: '币种')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('varchar', 128, nullable: false, default: '', comment: '活动标签')]
    public const schema_fields_CAMPAIGN_LABEL = 'campaign_label';

    #[Col('varchar', 512, nullable: false, default: '', comment: '商品 URL')]
    public const schema_fields_PRODUCT_URL = 'product_url';

    #[Col('varchar', 128, nullable: false, default: '', comment: '联系人')]
    public const schema_fields_CONTACT_NAME = 'contact_name';

    #[Col('varchar', 255, nullable: false, default: '', comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 64, nullable: false, default: '', comment: '手机')]
    public const schema_fields_PHONE = 'phone';

    #[Col('int', 11, nullable: false, default: 1, comment: '需求数量')]
    public const schema_fields_QUANTITY = 'quantity';

    #[Col('text', nullable: true, comment: '备注')]
    public const schema_fields_MESSAGE = 'message';

    #[Col('text', nullable: true, comment: '地址 JSON（邮编/国家/省市区/详细地址）')]
    public const schema_fields_ADDRESS_JSON = 'address_json';

    #[Col('varchar', 20, nullable: false, default: self::STATUS_NEW, comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 32, nullable: false, default: '', comment: '语言')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('bigint', 20, nullable: true, comment: '顾客 ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('timestamp', nullable: true, comment: '后台回复/处理时间')]
    public const schema_fields_ADMIN_REPLY_AT = 'admin_reply_at';

    #[Col('timestamp', nullable: true, comment: '顾客最近查看时间')]
    public const schema_fields_CUSTOMER_LAST_SEEN_AT = 'customer_last_seen_at';

    #[Col('varchar', 128, nullable: false, comment: '幂等键')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: true, comment: '来源摘要')]
    public const schema_fields_SOURCE_FINGERPRINT = 'source_fingerprint';

    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /** @var list<string> */
    public array $_unit_primary_keys = [self::schema_fields_ID];

    /** @var list<string> */
    public array $_index_sort_keys = [
        self::schema_fields_ID,
        self::schema_fields_STATUS,
        self::schema_fields_CREATED_AT,
        self::schema_fields_PRODUCT_ID,
        self::schema_fields_CUSTOMER_ID,
    ];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
