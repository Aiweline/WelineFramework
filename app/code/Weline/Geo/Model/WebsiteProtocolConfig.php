<?php

declare(strict_types=1);

namespace Weline\Geo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Website GEO protocol configuration')]
#[Index(name: 'uniq_weline_geo_website_protocol_config_website', columns: ['website_id'], type: 'UNIQUE')]
class WebsiteProtocolConfig extends Model
{
    public const schema_table = 'weline_geo_website_protocol_config';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether llms.txt/ai.txt output is enabled')]
    public const schema_fields_LLMS_ENABLED = 'llms_enabled';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether GEO feed output is enabled')]
    public const schema_fields_FEED_ENABLED = 'feed_enabled';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether automatic GEO push is enabled')]
    public const schema_fields_AUTO_PUSH = 'auto_push';

    #[Col('int', 0, nullable: false, default: 0, comment: 'Preferred GEO feed ID')]
    public const schema_fields_FEED_ID = 'feed_id';

    #[Col('text', comment: 'llms.txt introduction')]
    public const schema_fields_LLMS_INTRO = 'llms_intro';

    #[Col('datetime', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function loadByWebsiteId(int $websiteId): self
    {
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveForWebsite(int $websiteId, array $data): self
    {
        $this->loadByWebsiteId($websiteId);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }

        $this->setData(self::schema_fields_LLMS_ENABLED, !empty($data['llms_enabled']) ? 1 : 0)
            ->setData(self::schema_fields_FEED_ENABLED, !empty($data['feed_enabled']) ? 1 : 0)
            ->setData(self::schema_fields_AUTO_PUSH, !empty($data['auto_push']) ? 1 : 0)
            ->setData(self::schema_fields_FEED_ID, max(0, (int)($data['feed_id'] ?? 0)))
            ->setData(self::schema_fields_LLMS_INTRO, trim((string)($data['llms_intro'] ?? '')))
            ->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this;
    }

    public function isLlmsEnabled(): bool
    {
        return $this->getId() ? (int)$this->getData(self::schema_fields_LLMS_ENABLED) === 1 : true;
    }

    public function isFeedEnabled(): bool
    {
        return $this->getId() ? (int)$this->getData(self::schema_fields_FEED_ENABLED) === 1 : true;
    }

    public function isAutoPushEnabled(): bool
    {
        return $this->getId() ? (int)$this->getData(self::schema_fields_AUTO_PUSH) === 1 : true;
    }

    public function getFeedId(): int
    {
        return max(0, (int)($this->getData(self::schema_fields_FEED_ID) ?? 0));
    }

    public function getLlmsIntro(): string
    {
        return trim((string)($this->getData(self::schema_fields_LLMS_INTRO) ?? ''));
    }
}
