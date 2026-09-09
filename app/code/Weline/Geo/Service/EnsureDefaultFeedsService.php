<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Geo\Model\Feed;
use Weline\Geo\Model\WebsiteProtocolConfig;

/**
 * Seeds the default site GEO feeds and keeps website protocol discovery enabled.
 */
final class EnsureDefaultFeedsService
{
    public const SOURCE_CODE_CONTENT = 'default_content';
    public const SOURCE_CODE_PRODUCT = 'default_product';
    public const SOURCE_CODE_ARTICLE = 'default_article';

    public function __construct(
        private readonly Feed $feed,
        private readonly WebsiteProtocolConfig $protocolConfig,
    ) {
    }

    /**
     * @return array{feeds: array<string,int>, protocol_website_ids: list<int>}
     */
    public function ensure(): array
    {
        $defs = [
            self::SOURCE_CODE_CONTENT => [
                'name' => 'Site Content',
                'type' => Feed::TYPE_CONTENT,
                'description' => 'Default catch-all GEO feed for site content.',
            ],
            self::SOURCE_CODE_PRODUCT => [
                'name' => 'Products',
                'type' => Feed::TYPE_PRODUCT,
                'description' => 'Default GEO feed for published products.',
            ],
            self::SOURCE_CODE_ARTICLE => [
                'name' => 'Articles',
                'type' => Feed::TYPE_ARTICLE,
                'description' => 'Default GEO feed for published articles/blog posts.',
            ],
        ];

        $feedIds = [];
        foreach ($defs as $code => $def) {
            $feedIds[$code] = $this->ensureFeed($code, $def['name'], $def['type'], $def['description']);
        }

        $protocolWebsiteIds = $this->ensureProtocolEnabled();

        return [
            'feeds' => $feedIds,
            'protocol_website_ids' => $protocolWebsiteIds,
        ];
    }

    private function ensureFeed(string $code, string $name, string $type, string $description): int
    {
        $existing = $this->findBySourceCode($code);
        if ($existing !== null) {
            // Keep operator-controlled enable/auto-push; do not force re-enable on ensure.
            return (int)$existing->getId();
        }

        $now = time();
        $feed = clone $this->feed;
        $feed->clearData()->reset();
        $feed->setData([
            Feed::schema_fields_FEED_NAME => $name,
            Feed::schema_fields_FEED_TYPE => $type,
            Feed::schema_fields_SOURCE_TYPE => Feed::SOURCE_DATABASE,
            Feed::schema_fields_SOURCE_CONFIG => json_encode([
                'code' => $code,
                'seeded' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Feed::schema_fields_FEED_URL => '/geo-feed.xml',
            Feed::schema_fields_UPDATE_FREQUENCY => Feed::FREQUENCY_EVERY_10_MIN,
            Feed::schema_fields_IS_AUTO_PUSH => 1,
            Feed::schema_fields_IS_ENABLED => 1,
            Feed::schema_fields_CONFIG => json_encode([
                'description' => $description,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Feed::schema_fields_CREATED_AT => $now,
            Feed::schema_fields_UPDATED_AT => $now,
        ])->save();

        return (int)$feed->getId();
    }

    private function findBySourceCode(string $code): ?Feed
    {
        try {
            $rows = $this->feed->reset()->select()->fetchArray();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            $config = $row[Feed::schema_fields_SOURCE_CONFIG] ?? '';
            $decoded = is_string($config) ? json_decode($config, true) : (is_array($config) ? $config : []);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['code'] ?? '') === $code) {
                $model = clone $this->feed;
                $model->clearData()->reset()->load((int)($row[Feed::schema_fields_ID] ?? 0));

                return $model->getId() ? $model : null;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function ensureProtocolEnabled(): array
    {
        $websiteIds = [0];
        try {
            if (class_exists(\Weline\Websites\Model\Website::class)) {
                /** @var \Weline\Websites\Model\Website $website */
                $website = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                foreach ($website->reset()->select()->fetchArray() as $row) {
                    $id = (int)($row[\Weline\Websites\Model\Website::schema_fields_ID] ?? $row['website_id'] ?? -1);
                    if ($id >= 0) {
                        $websiteIds[] = $id;
                    }
                }
            }
        } catch (\Throwable) {
        }
        $websiteIds = array_values(array_unique($websiteIds));

        foreach ($websiteIds as $websiteId) {
            $row = $this->protocolConfig->loadByWebsiteId($websiteId);
            $llmsIntro = $row->getId() ? $row->getLlmsIntro() : '';
            // feed_id=0 keeps llms/protocol aggregation across all enabled feeds.
            $this->protocolConfig->saveForWebsite($websiteId, [
                'llms_enabled' => $row->getId() ? (int)$row->getData(WebsiteProtocolConfig::schema_fields_LLMS_ENABLED) : 1,
                'feed_enabled' => 1,
                'auto_push' => $row->getId() ? (int)$row->getData(WebsiteProtocolConfig::schema_fields_AUTO_PUSH) : 1,
                // Keep 0 so protocol/llms aggregates every enabled feed's items.
                'feed_id' => 0,
                'llms_intro' => $llmsIntro,
            ]);
        }

        return $websiteIds;
    }
}
