<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Geo\Model\Feed;
use Weline\Geo\Model\FeedItem;
use Weline\Geo\Service\Protocol\GeoWebsiteFeedScope;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * Cron-owned GEO feed publish: sync sources, generate only when content changed.
 */
final class FeedScheduleService
{
    public const SITE_JSON = 'site.json';
    public const SITE_XML = 'site.xml';
    public const MIN_CHECK_INTERVAL_SECONDS = 600;

    public function __construct(
        private readonly EnsureDefaultFeedsService $ensureDefaultFeeds,
        private readonly FeedBackfillService $backfill,
        private readonly FeedGeneratorService $generator,
        private readonly Feed $feed,
        private readonly FeedItem $feedItem,
        private readonly GeoWebsiteFeedScope $websiteFeedScope,
        private readonly SeoWebsiteDirectory $websiteDirectory,
    ) {
    }

    /**
     * @return array{
     *   synced: array<string,int|array<string,int>>,
     *   checked: int,
     *   generated: int,
     *   skipped: int,
     *   site_generated: bool,
     *   errors: int,
     *   message: string
     * }
     */
    public function tick(bool $syncSources = true, int $backfillLimit = 5000): array
    {
        $ensured = $this->ensureDefaultFeeds->ensure();
        $this->normalizeFeedFrequencies();

        $synced = ['feeds' => $ensured['feeds']];
        if ($syncSources) {
            $synced = array_replace($synced, $this->backfill->backfill($backfillLimit));
        }

        $checked = 0;
        $generated = 0;
        $skipped = 0;
        $errors = 0;
        $anyGenerated = false;

        foreach ($this->enabledFeeds() as $feed) {
            $checked++;
            try {
                if (!$this->hasNewContent($feed)) {
                    $skipped++;
                    continue;
                }
                $this->generator->generateAndSaveFeed($feed, 'json_feed');
                $this->generator->generateAndSaveFeed($feed, 'rss');
                $generated++;
                $anyGenerated = true;
            } catch (\Throwable $e) {
                $errors++;
                w_log_error(sprintf(
                    '[Weline_Geo] FeedScheduleService generate failed: feed_id=%d error=%s',
                    (int)$feed->getId(),
                    $e->getMessage()
                ));
            }
        }

        $siteGenerated = false;
        try {
            if ($anyGenerated || !$this->siteFilesExist() || $this->siteNeedsRefresh()) {
                $this->publishSiteProtocolFiles();
                $siteGenerated = true;
            }
        } catch (\Throwable $e) {
            $errors++;
            w_log_error('[Weline_Geo] FeedScheduleService site publish failed: ' . $e->getMessage());
        }

        $message = sprintf(
            'GEO feed schedule checked=%d generated=%d skipped=%d site=%s errors=%d',
            $checked,
            $generated,
            $skipped,
            $siteGenerated ? 'yes' : 'no',
            $errors
        );

        return [
            'synced' => $synced,
            'checked' => $checked,
            'generated' => $generated,
            'skipped' => $skipped,
            'site_generated' => $siteGenerated,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    public function hasNewContent(Feed $feed): bool
    {
        $feedId = (int)$feed->getId();
        if ($feedId <= 0) {
            return false;
        }

        $lastGeneratedAt = (int)($feed->getData(Feed::schema_fields_LAST_GENERATED_AT) ?? 0);
        if ($lastGeneratedAt <= 0) {
            return true;
        }

        try {
            $row = $this->feedItem->reset()
                ->where(FeedItem::schema_fields_FEED_ID, $feedId)
                ->where(FeedItem::schema_fields_IS_PUBLISHED, 1)
                ->order(FeedItem::schema_fields_UPDATED_AT, 'DESC')
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($row) || $row === []) {
            return false;
        }

        $updated = (int)($row[FeedItem::schema_fields_UPDATED_AT] ?? 0);
        $created = (int)($row[FeedItem::schema_fields_CREATED_AT] ?? 0);
        $published = (int)($row[FeedItem::schema_fields_PUBLISHED_AT] ?? 0);
        $newest = max($updated, $created, $published);

        return $newest > $lastGeneratedAt;
    }

    public function siteJsonPath(int $websiteId = 0): string
    {
        return $this->websiteFeedScope->siteJsonPath($websiteId);
    }

    public function siteXmlPath(int $websiteId = 0): string
    {
        return $this->websiteFeedScope->siteXmlPath($websiteId);
    }

    public function readSiteFeed(string $format, int $websiteId = 0): ?string
    {
        $path = ($format === 'rss' || $format === 'xml')
            ? $this->siteXmlPath($websiteId)
            : $this->siteJsonPath($websiteId);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }

    private function publishSiteProtocolFiles(): void
    {
        $feeds = $this->enabledFeeds();
        $root = rtrim(FeedGeneratorService::FEED_DIR, '/');
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create GEO feed directory: ' . $root);
        }

        foreach ($this->websiteIds() as $websiteId) {
            $this->publishSiteProtocolFilesForWebsite($websiteId, $feeds);
        }
    }

    /**
     * @param list<Feed> $feeds
     */
    private function publishSiteProtocolFilesForWebsite(int $websiteId, array $feeds): void
    {
        $dir = $this->websiteFeedScope->siteFeedDirectory($websiteId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create website GEO feed directory: ' . $dir);
        }

        $jsonPath = $this->siteJsonPath($websiteId);
        $xmlPath = $this->siteXmlPath($websiteId);
        if ($feeds === []) {
            file_put_contents($jsonPath, $this->emptyJson());
            file_put_contents($xmlPath, $this->emptyXml());
            return;
        }

        $registered = $this->websiteDirectory->getWebsiteById($websiteId, true);
        $baseUrl = is_array($registered)
            ? $this->websiteDirectory->effectivePublicBaseUrl($registered)
            : '';
        $allowedHosts = $this->websiteFeedScope->allowedHosts($websiteId, $baseUrl);

        $channel = $feeds[0];
        $items = [];
        $seen = [];
        foreach ($feeds as $feed) {
            foreach ($this->generator->listPublishedItems($feed) as $item) {
                if (!$this->websiteFeedScope->itemBelongsToWebsite($item, $websiteId, $allowedHosts)) {
                    continue;
                }
                $url = trim((string)($item[FeedItem::schema_fields_URL] ?? $item['url'] ?? ''));
                $type = trim((string)($item[FeedItem::schema_fields_ITEM_TYPE] ?? $item['item_type'] ?? ''));
                $id = (string)($item[FeedItem::schema_fields_ITEM_ID] ?? $item['item_id'] ?? '');
                $key = $type . ':' . $id . ':' . $url;
                if ($url === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = $item;
            }
        }
        usort($items, static function (array $a, array $b): int {
            $left = (int)($a[FeedItem::schema_fields_PUBLISHED_AT] ?? $a['published_at'] ?? 0);
            $right = (int)($b[FeedItem::schema_fields_PUBLISHED_AT] ?? $b['published_at'] ?? 0);
            return $right <=> $left;
        });

        file_put_contents(
            $jsonPath,
            $this->generator->generateFeedFromItems($channel, $items, 'json_feed')
        );
        file_put_contents(
            $xmlPath,
            $this->generator->generateFeedFromItems($channel, $items, 'rss')
        );
    }

    /**
     * @return list<int>
     */
    private function websiteIds(): array
    {
        $ids = [0];
        try {
            if (!class_exists(\Weline\Websites\Model\Website::class)) {
                return $ids;
            }
            /** @var \Weline\Websites\Model\Website $website */
            $website = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            foreach ($website->reset()->select()->fetchArray() as $row) {
                $id = (int)($row[\Weline\Websites\Model\Website::schema_fields_ID] ?? $row['website_id'] ?? -1);
                if ($id >= 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        return array_values(array_unique($ids));
    }

    private function emptyJson(): string
    {
        return json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'GEO Feed',
            'items' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{"items":[]}';
    }

    private function emptyXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0"><channel><title>GEO Feed</title></channel></rss>';
    }

    private function siteFilesExist(): bool
    {
        foreach ($this->websiteIds() as $websiteId) {
            if (!is_file($this->siteJsonPath($websiteId)) || !is_file($this->siteXmlPath($websiteId))) {
                return false;
            }
        }

        return true;
    }

    private function siteNeedsRefresh(): bool
    {
        if (!$this->siteFilesExist()) {
            return true;
        }

        $oldestMtime = PHP_INT_MAX;
        foreach ($this->websiteIds() as $websiteId) {
            $mtime = min(
                (int)@filemtime($this->siteJsonPath($websiteId)),
                (int)@filemtime($this->siteXmlPath($websiteId))
            );
            if ($mtime <= 0) {
                return true;
            }
            $oldestMtime = min($oldestMtime, $mtime);
        }

        $row = $this->feedItem->reset()
            ->where(FeedItem::schema_fields_IS_PUBLISHED, 1)
            ->order(FeedItem::schema_fields_UPDATED_AT, 'DESC')
            ->find()
            ->fetchArray();
        if (!is_array($row) || $row === []) {
            return false;
        }
        $newest = max(
            (int)($row[FeedItem::schema_fields_UPDATED_AT] ?? 0),
            (int)($row[FeedItem::schema_fields_CREATED_AT] ?? 0),
            (int)($row[FeedItem::schema_fields_PUBLISHED_AT] ?? 0)
        );

        return $newest > $oldestMtime;
    }

    private function publishedItemCount(int $feedId): int
    {
        try {
            $rows = $this->feedItem->reset()
                ->where(FeedItem::schema_fields_FEED_ID, $feedId)
                ->where(FeedItem::schema_fields_IS_PUBLISHED, 1)
                ->limit(1)
                ->select()
                ->fetchArray();
            return is_array($rows) && $rows !== [] ? 1 : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<Feed>
     */
    private function enabledFeeds(): array
    {
        try {
            $rows = $this->feed->reset()
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->order(Feed::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $feeds = [];
        foreach ($rows as $row) {
            $id = (int)($row[Feed::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $model = clone $this->feed;
            $model->clearData()->reset()->load($id);
            if ((int)$model->getId() === $id) {
                $feeds[] = $model;
            }
        }

        return $feeds;
    }

    private function normalizeFeedFrequencies(): void
    {
        try {
            $rows = $this->feed->reset()->select()->fetchArray();
        } catch (\Throwable) {
            return;
        }
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $id = (int)($row[Feed::schema_fields_ID] ?? 0);
            $freq = (string)($row[Feed::schema_fields_UPDATE_FREQUENCY] ?? '');
            if ($id <= 0) {
                continue;
            }
            if ($freq === Feed::FREQUENCY_REALTIME || $freq === '') {
                $model = clone $this->feed;
                $model->clearData()->reset()->load($id);
                if ((int)$model->getId() === $id) {
                    $model->setData(Feed::schema_fields_UPDATE_FREQUENCY, Feed::FREQUENCY_EVERY_10_MIN)
                        ->setData(Feed::schema_fields_UPDATED_AT, time())
                        ->save();
                }
            }
        }
    }
}
