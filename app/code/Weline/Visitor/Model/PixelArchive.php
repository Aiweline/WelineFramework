<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * G07：像素冷归档表（热表明细镜像；仅手动迁移写入，G08 才可能删热）。
 *
 * - 保留原 `pixel_id`（唯一）便于对账与幂等跳过；
 * - 索引 `(website_id, created_at)` 供冷查明细（G09）；
 * - 不承载聚合指标；高基 path/url 随明细保留。
 */
#[Table(comment: '像素冷归档明细 G07')]
#[Index(name: 'uk_pixel_archive_pixel_id', columns: ['pixel_id'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_archive_site_created', columns: ['website_id', 'created_at'])]
#[Index(name: 'idx_pixel_archive_site_session', columns: ['website_id', 'session_id', 'created_at'])]
#[Index(name: 'idx_pixel_archive_site_channel', columns: ['website_id', 'channel_code', 'created_at'])]
class PixelArchive extends Model
{
    public const schema_table = 'pixel_archive';
    public const schema_primary_key = 'pixel_archive_id';

    /** @var list<string> 与热表对账/迁移时复制的业务列（不含归档元数据）。 */
    public const HOT_MIRROR_FIELDS = [
        'pixel_id',
        'url',
        'module',
        'name',
        'referer',
        'source',
        'user_id',
        'user_agent',
        'ip',
        'event',
        'website_id',
        'lang',
        'currency',
        'value',
        'browser_info',
        'cron_deal',
        'created_at',
        'session_id',
        'channel_code',
        'channel_name',
        'traffic_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '归档主键')]
    public const schema_fields_ID = 'pixel_archive_id';

    #[Col('bigint', 0, nullable: false, comment: '原热表 pixel_id')]
    public const schema_fields_PIXEL_ID = 'pixel_id';

    #[Col('varchar', 255, comment: 'URL')]
    public const schema_fields_URL = 'url';

    #[Col('varchar', 255, nullable: false, default: '', comment: '模块')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 255, comment: '名称')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 255, comment: 'referer来源')]
    public const schema_fields_REFERER = 'referer';

    #[Col('varchar', 255, comment: '来源')]
    public const schema_fields_SOURCE = 'source';

    #[Col('int', 0, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';

    #[Col('varchar', 255, comment: '用户代理')]
    public const schema_fields_USER_AGENT = 'user_agent';

    #[Col('varchar', 45, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';

    #[Col('varchar', 255, nullable: false, default: '', comment: '事件')]
    public const schema_fields_EVENT = 'event';

    #[Col('int', 0, nullable: false, default: 0, comment: '网站ID；0=系统默认站点')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 255, nullable: false, default: '', comment: '语言')]
    public const schema_fields_LANG = 'lang';

    #[Col('varchar', 255, nullable: false, default: '', comment: '货币')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('decimal', '18,4', nullable: false, default: 0, comment: '价值')]
    public const schema_fields_VALUE = 'value';

    #[Col('text', comment: '浏览器信息')]
    public const schema_fields_BROWSER_INFO = 'browser_info';

    #[Col('int', 0, nullable: false, default: 0, comment: '定时处理标记快照')]
    public const schema_fields_CRON_DEAL = 'cron_deal';

    #[Col('datetime', comment: '原事件时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('varchar', 64, comment: '像素会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('varchar', 64, comment: '流量渠道码')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('varchar', 255, comment: '流量渠道名称快照')]
    public const schema_fields_CHANNEL_NAME = 'channel_name';

    #[Col('varchar', 32, comment: '流量类型')]
    public const schema_fields_TRAFFIC_TYPE = 'traffic_type';

    #[Col('varchar', 255, comment: 'utm_source')]
    public const schema_fields_UTM_SOURCE = 'utm_source';

    #[Col('varchar', 255, comment: 'utm_medium')]
    public const schema_fields_UTM_MEDIUM = 'utm_medium';

    #[Col('varchar', 255, comment: 'utm_campaign')]
    public const schema_fields_UTM_CAMPAIGN = 'utm_campaign';

    #[Col('datetime', nullable: false, comment: '迁入冷表时间')]
    public const schema_fields_ARCHIVED_AT = 'archived_at';

    public function getPixelArchiveId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getPixelId(): int
    {
        return (int)$this->getData(self::schema_fields_PIXEL_ID);
    }

    public function setPixelId(int $pixelId): static
    {
        return $this->setData(self::schema_fields_PIXEL_ID, $pixelId);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_CREATED_AT);
    }

    public function getArchivedAt(): string
    {
        return (string)$this->getData(self::schema_fields_ARCHIVED_AT);
    }

    public function setArchivedAt(string $archivedAt): static
    {
        return $this->setData(self::schema_fields_ARCHIVED_AT, $archivedAt);
    }
}
