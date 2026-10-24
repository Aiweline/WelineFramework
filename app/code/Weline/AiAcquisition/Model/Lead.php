<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI 获客客户记录。
 *
 * 一条记录对应一个从公开网络找到的潜客/企业，保留来源证据与 AI 推断，
 * 以 dedupe_hash 去重，可反复更新而不丢人工状态。
 */
#[Table(comment: 'AI获客客户记录')]
#[Index(name: 'idx_ai_acquisition_lead_dedupe', columns: ['dedupe_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_acquisition_lead_site_status', columns: ['website_id', 'status'])]
#[Index(name: 'idx_ai_acquisition_lead_continent', columns: ['continent_code'])]
#[Index(name: 'idx_ai_acquisition_lead_country', columns: ['country_code'])]
#[Index(name: 'idx_ai_acquisition_lead_email', columns: ['email'])]
#[Index(name: 'idx_ai_acquisition_lead_updated', columns: ['updated_at'])]
#[Index(name: 'idx_ai_acquisition_lead_entry_channel', columns: ['entry_channel', 'updated_at'])]
#[Index(name: 'idx_ai_acquisition_lead_type', columns: ['lead_type', 'status'])]
#[Index(name: 'idx_ai_acquisition_lead_customer', columns: ['customer_id'])]
#[Index(name: 'idx_ai_acquisition_lead_first_seen', columns: ['first_seen_date'])]
class Lead extends Model
{
    public const schema_table = 'ai_acquisition_lead';
    public const schema_primary_key = 'id';

    public const STATUS_NEW = 'new';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_NEW => '新线索',
        self::STATUS_QUALIFIED => '已确认',
        self::STATUS_CONTACTED => '已联系',
        self::STATUS_REPLIED => '已回复',
        self::STATUS_CONVERTED => '已成交',
        self::STATUS_REJECTED => '已排除',
    ];

    public const EMAIL_UNKNOWN = 'unknown';

    public const ENTRY_CHANNEL_DEFAULT = 'default';
    public const ENTRY_CHANNEL_BROWSER_MODEL = 'browser_model';
    public const ENTRY_CHANNEL_AI_SELECT = 'ai_select';
    public const ENTRY_CHANNEL_SKILL = 'skill';
    public const ENTRY_CHANNELS = [
        self::ENTRY_CHANNEL_DEFAULT => '默认录入',
        self::ENTRY_CHANNEL_BROWSER_MODEL => '浏览器模型录入',
        self::ENTRY_CHANNEL_AI_SELECT => 'AI 选择录入',
        self::ENTRY_CHANNEL_SKILL => '获客技能录入',
    ];

    public const LEAD_TYPE_RETAILER = 'retailer';
    public const LEAD_TYPE_RENTAL_STUDIO = 'rental_studio';
    public const LEAD_TYPE_SUPPLIER_FACTORY = 'supplier_factory';
    public const LEAD_TYPE_MARKETPLACE = 'marketplace';
    public const LEAD_TYPE_MEDIA_GUIDE = 'media_guide';
    public const LEAD_TYPE_DIRECTORY = 'directory';
    public const LEAD_TYPE_SOCIAL = 'social';
    public const LEAD_TYPE_UNKNOWN = 'unknown';

    public const LEAD_TYPES = [
        self::LEAD_TYPE_RETAILER => '零售商',
        self::LEAD_TYPE_RENTAL_STUDIO => '租赁工作室',
        self::LEAD_TYPE_SUPPLIER_FACTORY => '供应商/工厂',
        self::LEAD_TYPE_MARKETPLACE => '电商平台',
        self::LEAD_TYPE_MEDIA_GUIDE => '媒体/导购',
        self::LEAD_TYPE_DIRECTORY => '名录/黄页',
        self::LEAD_TYPE_SOCIAL => '社媒',
        self::LEAD_TYPE_UNKNOWN => '未知',
    ];
    public const EMAIL_VALID = 'valid';
    public const EMAIL_RISKY = 'risky';
    public const EMAIL_INVALID = 'invalid';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', default: 0, comment: '归属网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 8, default: 'UNKNOWN', comment: '洲代码')]
    public const schema_fields_CONTINENT_CODE = 'continent_code';
    #[Col('varchar', 8, default: 'XX', comment: '国家ISO2')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 100, default: '', comment: '国家名称')]
    public const schema_fields_COUNTRY_NAME = 'country_name';
    #[Col('varchar', 100, default: '', comment: '州/省/地区')]
    public const schema_fields_REGION = 'region';
    #[Col('varchar', 100, default: '', comment: '城市')]
    public const schema_fields_CITY = 'city';
    #[Col('decimal', '10,7', nullable: true, comment: '纬度')]
    public const schema_fields_LATITUDE = 'latitude';
    #[Col('decimal', '10,7', nullable: true, comment: '经度')]
    public const schema_fields_LONGITUDE = 'longitude';
    #[Col('varchar', 64, default: '', comment: '时区')]
    public const schema_fields_TIMEZONE = 'timezone';
    #[Col('varchar', 255, default: '', comment: '公司名称')]
    public const schema_fields_COMPANY_NAME = 'company_name';
    #[Col('varchar', 255, default: '', comment: '公司域名')]
    public const schema_fields_COMPANY_DOMAIN = 'company_domain';
    #[Col('varchar', 120, default: '', comment: '联系人')]
    public const schema_fields_CONTACT_NAME = 'contact_name';
    #[Col('varchar', 120, default: '', comment: '职位/角色')]
    public const schema_fields_CONTACT_ROLE = 'contact_role';
    #[Col('varchar', 255, default: '', comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col('varchar', 20, default: 'unknown', comment: '邮箱可用性')]
    public const schema_fields_EMAIL_STATUS = 'email_status';
    #[Col('varchar', 64, default: '', comment: '电话/WhatsApp')]
    public const schema_fields_PHONE = 'phone';
    #[Col('text', nullable: true, comment: '社交主页JSON')]
    public const schema_fields_SOCIAL_PROFILES = 'social_profiles';
    #[Col('varchar', 1024, default: '', comment: '来源URL')]
    public const schema_fields_SOURCE_URL = 'source_url';
    #[Col('varchar', 255, default: '', comment: '来源标题')]
    public const schema_fields_SOURCE_TITLE = 'source_title';
    #[Col('varchar', 64, default: 'web', comment: '来源平台')]
    public const schema_fields_SOURCE_PLATFORM = 'source_platform';
    #[Col('varchar', 32, default: 'default', comment: '录入渠道')]
    public const schema_fields_ENTRY_CHANNEL = 'entry_channel';
    #[Col('text', nullable: true, comment: '来源证据摘录')]
    public const schema_fields_SOURCE_EXCERPT = 'source_excerpt';
    #[Col('int', default: 0, comment: '来源发布时间')]
    public const schema_fields_SOURCE_PUBLISHED_AT = 'source_published_at';
    #[Col('text', nullable: true, comment: 'AI推断的偏好')]
    public const schema_fields_AI_PREFERENCE = 'ai_preference';
    #[Col('text', nullable: true, comment: 'AI推断的业务方向')]
    public const schema_fields_AI_BUSINESS_DIRECTION = 'ai_business_direction';
    #[Col('decimal', '5,2', default: 0, comment: 'AI资格分0-10')]
    public const schema_fields_AI_SCORE = 'ai_score';
    #[Col('text', nullable: true, comment: 'AI打分理由')]
    public const schema_fields_AI_REASON = 'ai_reason';
    #[Col('varchar', 100, default: '', comment: '推断所用模型')]
    public const schema_fields_AI_MODEL_CODE = 'ai_model_code';
    #[Col('int', default: 0, comment: 'AI推断时间')]
    public const schema_fields_AI_INFERRED_AT = 'ai_inferred_at';
    #[Col('varchar', 32, default: 'new', comment: '跟进状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: '标签JSON')]
    public const schema_fields_TAGS = 'tags';
    #[Col('int', default: 1, comment: '重复命中次数')]
    public const schema_fields_SEEN_COUNT = 'seen_count';
    #[Col('char', 64, nullable: false, comment: '去重哈希')]
    public const schema_fields_DEDUPE_HASH = 'dedupe_hash';
    #[Col('text', nullable: true, comment: '原始采集JSON')]
    public const schema_fields_RAW_JSON = 'raw_json';
    #[Col('varchar', 32, default: 'unknown', comment: '线索类型')]
    public const schema_fields_LEAD_TYPE = 'lead_type';
    #[Col('smallint', default: 0, comment: '是否外联目标')]
    public const schema_fields_OUTREACH_TARGET = 'outreach_target';
    #[Col('smallint', default: 0, comment: '是否AI自动回复')]
    public const schema_fields_AI_AUTO_REPLY = 'ai_auto_reply';
    #[Col('int', default: 0, comment: '转化客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('date', nullable: true, comment: '首次发现日期')]
    public const schema_fields_FIRST_SEEN_DATE = 'first_seen_date';
    #[Col('int', default: 0, comment: '最后命中时间')]
    public const schema_fields_LAST_SEEN_AT = 'last_seen_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'status', 'continent_code', 'updated_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $this->setData(self::schema_fields_LAST_SEEN_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }

    public function getStatusLabel(): string
    {
        $status = (string)$this->getData(self::schema_fields_STATUS);
        return (string)(self::STATUSES[$status] ?? $status);
    }

    public function getLeadTypeLabel(): string
    {
        $leadType = (string)$this->getData(self::schema_fields_LEAD_TYPE);
        return (string)(self::LEAD_TYPES[$leadType] ?? $leadType);
    }

    public function getEntryChannelLabel(): string
    {
        $channel = (string)$this->getData(self::schema_fields_ENTRY_CHANNEL);

        return (string)(self::ENTRY_CHANNELS[$channel] ?? $channel);
    }
}
