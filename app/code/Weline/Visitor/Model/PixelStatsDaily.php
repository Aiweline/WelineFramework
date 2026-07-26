<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * G02：像素日聚合温表（仅 schema；不跑 Cron，写入与校验见 G05）。
 *
 * 日表沿用 G01 的流量维和 dim_hash 契约，并增加不能由小时表安全求和得到的
 * 权威会话指标与漏斗摘要。`sessions` 是按 session_id 日内去重的权威会话数；
 * 禁止用小时表 `session_starts` 求和替代。
 *
 * `funnel_json` 只保存日聚合任务计算出的漏斗摘要，不保存事件明细，也不成为
 * 新的事实源。高基 page_path/url 不进入默认全维。
 */
#[Table(comment: '像素日聚合温表 G02')]
#[Index(name: 'uk_pixel_stats_daily_bucket', columns: ['day_bucket', 'website_id', 'dim_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_stats_daily_site_bucket', columns: ['website_id', 'day_bucket'])]
#[Index(name: 'idx_pixel_stats_daily_channel', columns: ['website_id', 'channel_code', 'day_bucket'])]
#[Index(name: 'idx_pixel_stats_daily_traffic', columns: ['website_id', 'traffic_type', 'day_bucket'])]
#[Index(name: 'idx_pixel_stats_daily_event', columns: ['website_id', 'event_name', 'day_bucket'])]
class PixelStatsDaily extends Model
{
    public const schema_table = 'pixel_stats_daily';
    public const schema_primary_key = 'pixel_stats_daily_id';

    /**
     * 与 G01 完全相同且顺序冻结，避免小时/日表产生不同 dim_hash。
     *
     * @var list<string>
     */
    public const DIM_FIELDS = PixelStatsHourly::DIM_FIELDS;

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'pixel_stats_daily_id';

    #[Col('date', nullable: false, comment: '日桶（站点时区）')]
    public const schema_fields_DAY_BUCKET = 'day_bucket';

    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID；0=系统默认站点')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('char', 40, nullable: false, comment: '流量维有序序列化 sha1')]
    public const schema_fields_DIM_HASH = 'dim_hash';

    #[Col('varchar', 64, nullable: false, default: 'UTC', comment: '日桶所用时区')]
    public const schema_fields_TZ = 'tz';

    #[Col('varchar', 32, nullable: false, default: '', comment: '流量类型维')]
    public const schema_fields_TRAFFIC_TYPE = 'traffic_type';

    #[Col('varchar', 64, nullable: false, default: '', comment: '渠道码维')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'utm_source 维')]
    public const schema_fields_UTM_SOURCE = 'utm_source';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'utm_medium 维')]
    public const schema_fields_UTM_MEDIUM = 'utm_medium';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'utm_campaign 维')]
    public const schema_fields_UTM_CAMPAIGN = 'utm_campaign';

    #[Col('varchar', 128, nullable: false, default: '', comment: '事件名维')]
    public const schema_fields_EVENT_NAME = 'event_name';

    #[Col('varchar', 32, nullable: false, default: '', comment: '设备类别维')]
    public const schema_fields_DEVICE_CATEGORY = 'device_category';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '事件数 COUNT(*)')]
    public const schema_fields_EVENTS = 'events';

    #[Col('decimal', '18,4', nullable: false, default: 0, comment: '价值合计 SUM(value)')]
    public const schema_fields_VALUE_SUM = 'value_sum';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '有价值事件数 value>0')]
    public const schema_fields_VALUED_EVENTS = 'valued_events';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '会话起始数（当日 first_at）')]
    public const schema_fields_SESSION_STARTS = 'session_starts';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '购买数')]
    public const schema_fields_PURCHASES = 'purchases';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '加购数')]
    public const schema_fields_ADD_TO_CARTS = 'add_to_carts';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '日内 session_id 去重的权威会话数')]
    public const schema_fields_SESSIONS = 'sessions';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '参与会话数')]
    public const schema_fields_ENGAGED_SESSIONS = 'engaged_sessions';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '跳出会话数')]
    public const schema_fields_BOUNCE_SESSIONS = 'bounce_sessions';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '转化数')]
    public const schema_fields_CONVERSIONS = 'conversions';

    #[Col('text', comment: '日漏斗聚合摘要 JSON；非事件事实源')]
    public const schema_fields_FUNNEL_JSON = 'funnel_json';

    #[Col('datetime', comment: '首次写入时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '最后重跑覆盖时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * @param array<string, mixed> $dims
     */
    public static function dimHash(array $dims): string
    {
        return PixelStatsHourly::dimHash($dims);
    }

    /**
     * @param array<string, mixed> $dims
     * @return array<string, string>
     */
    public static function normalizeDims(array $dims): array
    {
        return PixelStatsHourly::normalizeDims($dims);
    }

    public function getPixelStatsDailyId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getDayBucket(): string
    {
        return (string)$this->getData(self::schema_fields_DAY_BUCKET);
    }

    public function setDayBucket(string $dayBucket): static
    {
        return $this->setData(self::schema_fields_DAY_BUCKET, $dayBucket);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getDimHash(): string
    {
        return (string)$this->getData(self::schema_fields_DIM_HASH);
    }

    public function setDimHash(string $dimHash): static
    {
        return $this->setData(self::schema_fields_DIM_HASH, $dimHash);
    }

    public function getTz(): string
    {
        return (string)$this->getData(self::schema_fields_TZ);
    }

    public function setTz(string $tz): static
    {
        return $this->setData(self::schema_fields_TZ, $tz);
    }
}
