<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\FeedItem;

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

    public function siteJsonPath(): string
    {
        return rtrim(FeedGeneratorService::FEED_DIR, '/') . '/' . self::SITE_JSON;
    }

    public function siteXmlPath(): string
    {
        return rtrim(FeedGeneratorService::FEED_DIR, '/') . '/' . self::SITE_XML;
    }

    public function readSiteFeed(string $format): ?string
    {
        $path = ($format === 'rss' || $format === 'xml') ? $this->siteXmlPath() : $this->siteJsonPath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }

    private function publishSiteProtocolFiles(): void
    {
        $feeds = $this->enabledFeeds();
        $dir = FeedGeneratorService::FEED_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if ($feeds === []) {
            file_put_contents($this->siteJsonPath(), $this->emptyJson());
            file_put_contents($this->siteXmlPath(), $this->emptyXml());
            return;
        }

        $channel = $feeds[0];
        $items = [];
        $seen = [];
        foreach ($feeds as $feed) {
            foreach ($this->generator->listPublishedItems($feed) as $item) {
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
            $this->siteJsonPath(),
            $this->generator->generateFeedFromItems($channel, $items, 'json_feed')
        );
        file_put_contents(
            $this->siteXmlPath(),
            $this->generator->generateFeedFromItems($channel, $items, 'rss')
        );
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
        return is_file($this->siteJsonPath()) && is_file($this->siteXmlPath());
    }

    private function siteNeedsRefresh(): bool
    {
        if (!$this->siteFilesExist()) {
            return true;
        }
        $mtime = min(
            (int)@filemtime($this->siteJsonPath()),
            (int)@filemtime($this->siteXmlPath())
        );
        if ($mtime <= 0) {
            return true;
        }

        try {
            $row = $this->feedItem->reset()
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
        $newest = max(
            (int)($row[FeedItem::schema_fields_UPDATED_AT] ?? 0),
            (int)($row[FeedItem::schema_fields_CREATED_AT] ?? 0),
            (int)($row[FeedItem::schema_fields_PUBLISHED_AT] ?? 0)
        );

        return $newest > $mtime;
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
