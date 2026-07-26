<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 像素流量渠道：campaign（投放）与 rule（匹配规则）共用表。
 * B01：仅 schema；校验/CRUD/归因查表分属后续 WP。
 */
#[Table(comment: '像素流量渠道 campaign/rule')]
#[Index(name: 'uk_pixel_channel_website_code', columns: ['website_id', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_channel_kind_enabled', columns: ['kind', 'enabled', 'website_id'])]
#[Index(name: 'idx_pixel_channel_traffic_type', columns: ['website_id', 'traffic_type'])]
#[Index(name: 'idx_pixel_channel_rule_priority', columns: ['kind', 'priority', 'enabled'])]
class PixelChannel extends Model
{
    public const schema_table = 'pixel_channel';
    public const schema_primary_key = 'pixel_channel_id';

    public const KIND_CAMPAIGN = 'campaign';
    public const KIND_RULE = 'rule';

    public const TRAFFIC_PAID = 'paid';
    public const TRAFFIC_SOCIAL = 'social';
    public const TRAFFIC_ORGANIC = 'organic';
    public const TRAFFIC_EMAIL = 'email';
    public const TRAFFIC_REFERRAL = 'referral';
    public const TRAFFIC_DIRECT = 'direct';
    public const TRAFFIC_CUSTOM = 'custom';

    public const MATCH_QUERY_PARAM = 'query_param';
    public const MATCH_UTM_SOURCE = 'utm_source';
    public const MATCH_UTM_MEDIUM = 'utm_medium';
    public const MATCH_REFERER_HOST = 'referer_host';
    public const MATCH_CLICK_ID = 'click_id';

    /** @var list<string> */
    public const KINDS = [self::KIND_CAMPAIGN, self::KIND_RULE];

    /** @var list<string> */
    public const TRAFFIC_TYPES = [
        self::TRAFFIC_PAID,
        self::TRAFFIC_SOCIAL,
        self::TRAFFIC_ORGANIC,
        self::TRAFFIC_EMAIL,
        self::TRAFFIC_REFERRAL,
        self::TRAFFIC_DIRECT,
        self::TRAFFIC_CUSTOM,
    ];

    /** @var list<string> */
    public const MATCH_MODES = [
        self::MATCH_QUERY_PARAM,
        self::MATCH_UTM_SOURCE,
        self::MATCH_UTM_MEDIUM,
        self::MATCH_REFERER_HOST,
        self::MATCH_CLICK_ID,
    ];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '渠道ID')]
    public const schema_fields_ID = 'pixel_channel_id';

    #[Col('varchar', 16, nullable: false, default: self::KIND_CAMPAIGN, comment: '类型 campaign|rule')]
    public const schema_fields_KIND = 'kind';

    #[Col('varchar', 64, nullable: false, comment: '渠道码；campaign 校验见 B02')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, comment: '渠道名称')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 32, nullable: false, default: self::TRAFFIC_CUSTOM, comment: '流量类型')]
    public const schema_fields_TRAFFIC_TYPE = 'traffic_type';

    #[Col('varchar', 255, comment: 'utm_source')]
    public const schema_fields_UTM_SOURCE = 'utm_source';

    #[Col('varchar', 255, comment: 'utm_medium')]
    public const schema_fields_UTM_MEDIUM = 'utm_medium';

    #[Col('varchar', 255, comment: 'utm_campaign')]
    public const schema_fields_UTM_CAMPAIGN = 'utm_campaign';

    #[Col('varchar', 32, comment: 'rule 匹配模式')]
    public const schema_fields_MATCH_MODE = 'match_mode';

    #[Col('varchar', 255, comment: 'rule 匹配值')]
    public const schema_fields_MATCH_VALUE = 'match_value';

    #[Col('int', 0, nullable: false, default: 100, comment: 'rule 优先级，越小越先')]
    public const schema_fields_PRIORITY = 'priority';

    #[Col('int', 0, nullable: false, default: 1, comment: '是否启用 1/0')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID；0=全局')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 512, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getPixelChannelId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getKind(): string
    {
        return (string)$this->getData(self::schema_fields_KIND);
    }

    public function setKind(string $kind): static
    {
        return $this->setData(self::schema_fields_KIND, $kind);
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getTrafficType(): string
    {
        return (string)$this->getData(self::schema_fields_TRAFFIC_TYPE);
    }

    public function setTrafficType(string $trafficType): static
    {
        return $this->setData(self::schema_fields_TRAFFIC_TYPE, $trafficType);
    }

    public function getUtmSource(): string
    {
        return (string)$this->getData(self::schema_fields_UTM_SOURCE);
    }

    public function setUtmSource(?string $utmSource): static
    {
        return $this->setData(self::schema_fields_UTM_SOURCE, $utmSource);
    }

    public function getUtmMedium(): string
    {
        return (string)$this->getData(self::schema_fields_UTM_MEDIUM);
    }

    public function setUtmMedium(?string $utmMedium): static
    {
        return $this->setData(self::schema_fields_UTM_MEDIUM, $utmMedium);
    }

    public function getUtmCampaign(): string
    {
        return (string)$this->getData(self::schema_fields_UTM_CAMPAIGN);
    }

    public function setUtmCampaign(?string $utmCampaign): static
    {
        return $this->setData(self::schema_fields_UTM_CAMPAIGN, $utmCampaign);
    }

    public function getMatchMode(): string
    {
        return (string)$this->getData(self::schema_fields_MATCH_MODE);
    }

    public function setMatchMode(?string $matchMode): static
    {
        return $this->setData(self::schema_fields_MATCH_MODE, $matchMode);
    }

    public function getMatchValue(): string
    {
        return (string)$this->getData(self::schema_fields_MATCH_VALUE);
    }

    public function setMatchValue(?string $matchValue): static
    {
        return $this->setData(self::schema_fields_MATCH_VALUE, $matchValue);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): static
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_ENABLED) === 1;
    }

    public function setEnabled(bool $enabled): static
    {
        return $this->setData(self::schema_fields_ENABLED, $enabled ? 1 : 0);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }

    public function setDescription(?string $description): static
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_CREATED_AT);
    }

    public function isCampaign(): bool
    {
        return $this->getKind() === self::KIND_CAMPAIGN;
    }

    public function isRule(): bool
    {
        return $this->getKind() === self::KIND_RULE;
    }

    public function isGlobal(): bool
    {
        return $this->getWebsiteId() === 0;
    }
}
