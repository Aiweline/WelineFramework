<?php

declare(strict_types=1);

namespace Weline\Promotion\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 前台活动主题（按 website / store / channel 范围投放） @package Weline_Promotion */
#[Table(comment: '前台活动主题')]
#[Index(name: 'idx_promotion_theme_scope_slug', columns: ['website_id', 'store_code', 'channel_code', 'page_slug'], type: 'UNIQUE')]
#[Index(name: 'idx_promotion_theme_status_sort', columns: ['status', 'sort_order'])]
class PromotionActivityTheme extends Model
{
    public const schema_table = 'weline_promotion_activity_theme';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'theme_key', 'page_slug', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '主题键')]
    public const schema_fields_THEME_KEY = 'theme_key';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '前台路径 slug（promotion/{slug}）')]
    public const schema_fields_PAGE_SLUG = 'page_slug';
    #[Col(type: 'int', nullable: false, default: 0, comment: '网站 ID，0=全部网站')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '店铺 code，空=全部店铺')]
    public const schema_fields_STORE_CODE = 'store_code';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '渠道 code，空=全部渠道')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'tinyint', nullable: false, default: 1, comment: '是否显示在二级导航')]
    public const schema_fields_IS_NAV_TAB = 'is_nav_tab';
    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '商品筛选 price band')]
    public const schema_fields_PRICE_BAND = 'price_band';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'manual', comment: '选品模式 manual|filter|single')]
    public const schema_fields_PRODUCT_PICK_MODE = 'product_pick_mode';
    #[Col(type: 'text', nullable: true, comment: '条件选品 JSON')]
    public const schema_fields_PRODUCT_FILTER_JSON = 'product_filter_json';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'none', comment: '活动折扣类型 none|percentage|fixed_amount')]
    public const schema_fields_DEAL_DISCOUNT_TYPE = 'deal_discount_type';
    #[Col(type: 'decimal', length: '12,2', nullable: false, default: 0, comment: '活动折扣值')]
    public const schema_fields_DEAL_DISCOUNT_VALUE = 'deal_discount_value';
    #[Col(type: 'int', nullable: false, default: 0, comment: '同步的 Marketing 规则 ID')]
    public const schema_fields_MARKETING_RULE_ID = 'marketing_rule_id';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED];
    }
}
