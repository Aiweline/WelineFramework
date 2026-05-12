<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Website SEO protocol configuration')]
#[Index(name: 'uniq_weline_seo_website_protocol_config_website', columns: ['website_id'], type: 'UNIQUE')]
class WebsiteProtocolConfig extends Model
{
    public const schema_table = 'weline_seo_website_protocol_config';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether robots protocol output is enabled')]
    public const schema_fields_ROBOTS_ENABLED = 'robots_enabled';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether sitemap link is included in robots')]
    public const schema_fields_SITEMAP_ENABLED = 'sitemap_enabled';

    #[Col('varchar', 20, nullable: false, default: 'allow', comment: 'Google-Extended policy: allow/disallow')]
    public const schema_fields_GOOGLE_EXTENDED = 'google_extended';

    #[Col('text', comment: 'Additional robots.txt directives')]
    public const schema_fields_ROBOTS_EXTRA = 'robots_extra';

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

        $this->setData(self::schema_fields_ROBOTS_ENABLED, !empty($data['robots_enabled']) ? 1 : 0)
            ->setData(self::schema_fields_SITEMAP_ENABLED, !empty($data['sitemap_enabled']) ? 1 : 0)
            ->setData(self::schema_fields_GOOGLE_EXTENDED, $this->normalizeGoogleExtended((string)($data['google_extended'] ?? 'allow')))
            ->setData(self::schema_fields_ROBOTS_EXTRA, $this->normalizeRobotsExtra((string)($data['robots_extra'] ?? '')))
            ->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this;
    }

    public function isRobotsEnabled(): bool
    {
        return $this->getId() ? (int)$this->getData(self::schema_fields_ROBOTS_ENABLED) === 1 : true;
    }

    public function isSitemapEnabled(): bool
    {
        return $this->getId() ? (int)$this->getData(self::schema_fields_SITEMAP_ENABLED) === 1 : true;
    }

    public function getGoogleExtendedPolicy(): string
    {
        return $this->normalizeGoogleExtended((string)($this->getData(self::schema_fields_GOOGLE_EXTENDED) ?: 'allow'));
    }

    public function getRobotsExtra(): string
    {
        return (string)($this->getData(self::schema_fields_ROBOTS_EXTRA) ?? '');
    }

    private function normalizeGoogleExtended(string $policy): string
    {
        $policy = strtolower(trim($policy));
        return $policy === 'disallow' ? 'disallow' : 'allow';
    }

    private function normalizeRobotsExtra(string $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $clean[] = str_replace(["\r", "\n"], '', $line);
        }

        return implode("\n", $clean);
    }
}
