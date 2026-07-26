<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * G01：像素小时聚合温表（仅 schema；不跑 Cron，写入见 G04）。
 *
 * 口径（§2.5 / §3.3）：
 * - 唯一键 `(hour_bucket, website_id, dim_hash)`，UPSERT 幂等重跑覆盖；
 * - `hour_bucket` 按 **站点时区**（无则 UTC）取整到小时，`tz` 记录所用时区；
 * - `dim_hash` = 对流量维有序序列化后 sha1（维见 self::DIM_FIELDS，缺省维用空串）；
 * - 维度列冗余存放，便于报表按单维 group-by / 过滤；
 * - 指标仅小时级：events / value_sum / valued_events / session_starts /
 *   purchases / add_to_carts。**禁止**把 session_starts 当会话数 SUM，
 *   权威会话数在日表（G02）。
 * - **禁止**把高基 `page_path` 打进本表默认全维（页面 TopN 另任务）。
 */
#[Table(comment: '像素小时聚合温表 G01')]
#[Index(name: 'uk_pixel_stats_hourly_bucket', columns: ['hour_bucket', 'website_id', 'dim_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_stats_hourly_site_bucket', columns: ['website_id', 'hour_bucket'])]
#[Index(name: 'idx_pixel_stats_hourly_channel', columns: ['website_id', 'channel_code', 'hour_bucket'])]
#[Index(name: 'idx_pixel_stats_hourly_traffic', columns: ['website_id', 'traffic_type', 'hour_bucket'])]
#[Index(name: 'idx_pixel_stats_hourly_event', columns: ['website_id', 'event_name', 'hour_bucket'])]
class PixelStatsHourly extends Model
{
    public const schema_table = 'pixel_stats_hourly';
    public const schema_primary_key = 'pixel_stats_hourly_id';

    /**
     * dim_hash 参与序列化的流量维（有序，勿改动顺序，改动即破坏历史 hash 幂等）。
     *
     * @var list<string>
     */
    public const DIM_FIELDS = [
        'traffic_type',
        'channel_code',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'event_name',
        'device_category',
    ];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'pixel_stats_hourly_id';

    #[Col('datetime', nullable: false, comment: '小时桶（站点时区取整到小时）')]
    public const schema_fields_HOUR_BUCKET = 'hour_bucket';

    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID；0=系统默认站点')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('char', 40, nullable: false, comment: '流量维有序序列化 sha1')]
    public const schema_fields_DIM_HASH = 'dim_hash';

    #[Col('varchar', 64, nullable: false, default: 'UTC', comment: '小时桶所用时区')]
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

    #[Col('bigint', 0, nullable: false, default: 0, comment: '会话起始数（当桶 first_at）；禁止当会话数 SUM')]
    public const schema_fields_SESSION_STARTS = 'session_starts';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '购买数')]
    public const schema_fields_PURCHASES = 'purchases';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '加购数')]
    public const schema_fields_ADD_TO_CARTS = 'add_to_carts';

    #[Col('datetime', comment: '首次写入时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '最后重跑覆盖时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * dim_hash 契约：对 DIM_FIELDS 有序取值，逐维小写去空白后用 `\x1f` 连接再 sha1。
     * 缺省维用空串占位，保证维数固定、跨行可比、跨 WP 稳定。
     *
     * @param array<string, mixed> $dims
     */
    public static function dimHash(array $dims): string
    {
        $parts = [];
        foreach (self::DIM_FIELDS as $field) {
            $value = $dims[$field] ?? '';
            $parts[] = strtolower(trim((string)$value));
        }

        return sha1(implode("\x1f", $parts));
    }

    /**
     * 仅保留 DIM_FIELDS 白名单维，其余键丢弃；用于落库前归一。
     *
     * @param array<string, mixed> $dims
     * @return array<string, string>
     */
    public static function normalizeDims(array $dims): array
    {
        $normalized = [];
        foreach (self::DIM_FIELDS as $field) {
            $normalized[$field] = strtolower(trim((string)($dims[$field] ?? '')));
        }

        return $normalized;
    }

    public function getPixelStatsHourlyId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getHourBucket(): string
    {
        return (string)$this->getData(self::schema_fields_HOUR_BUCKET);
    }

    public function setHourBucket(string $hourBucket): static
    {
        return $this->setData(self::schema_fields_HOUR_BUCKET, $hourBucket);
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
