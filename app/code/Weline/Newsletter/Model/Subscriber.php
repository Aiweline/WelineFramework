<?php

declare(strict_types=1);

namespace Weline\Newsletter\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '邮件订阅台账（含欢迎礼券绑定）')]
#[Index(name: 'uniq_newsletter_website_email', columns: ['website_id', 'email'], type: 'UNIQUE')]
#[Index(name: 'idx_newsletter_gift_status', columns: ['gift_status', 'status'])]
class Subscriber extends Model
{
    public const schema_table = 'weline_newsletter_subscriber';
    public const schema_primary_key = 'subscriber_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const GIFT_NONE = 'none';
    public const GIFT_ISSUED = 'issued';
    public const GIFT_REDEEMED = 'redeemed';
    public const GIFT_SKIPPED = 'skipped';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '订阅 ID')]
    public const schema_fields_ID = 'subscriber_id';

    #[Col('int', nullable: false, default: 0, comment: '网站 ID（0=默认站）')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 255, nullable: false, comment: '规范化邮箱（lower）')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'active/unsubscribed')]
    public const schema_fields_STATUS = 'status';

    #[Col('smallint', 1, nullable: false, default: 1, comment: '优惠活动主题')]
    public const schema_fields_TOPIC_PROMO = 'topic_promo';

    #[Col('smallint', 1, nullable: false, default: 1, comment: '上新主题')]
    public const schema_fields_TOPIC_NEW_ARRIVALS = 'topic_new_arrivals';

    #[Col('int', nullable: true, comment: '关联客户 ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('varchar', 32, nullable: false, default: '', comment: 'footer/popup/api')]
    public const schema_fields_SOURCE_SURFACE = 'source_surface';

    #[Col('varchar', 32, nullable: false, default: '', comment: '提交时 locale')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('int', nullable: true, comment: '欢迎券 ID')]
    public const schema_fields_COUPON_ID = 'coupon_id';

    #[Col('varchar', 64, nullable: true, comment: '欢迎券码')]
    public const schema_fields_COUPON_CODE = 'coupon_code';

    #[Col('varchar', 16, nullable: false, default: 'none', comment: 'none/issued/redeemed/skipped')]
    public const schema_fields_GIFT_STATUS = 'gift_status';

    #[Col('datetime', nullable: true, comment: '发券时间 UTC')]
    public const schema_fields_GIFT_ISSUED_AT = 'gift_issued_at';

    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_EMAIL,
        self::schema_fields_STATUS,
        self::schema_fields_GIFT_STATUS,
    ];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
