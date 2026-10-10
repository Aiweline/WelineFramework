<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Event\EventsManager;

final class SearchStorefrontCacheCoordinator
{
    public const EVENT_HOT_WORDS_CHANGED = 'Weline_Search::hot_words_changed';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly EventsManager $events,
    ) {
    }

    /**
     * Shared query result bag (channel × lang × currency × area).
     * Cross-scope cold-chaos: projection sticks but type=all fan-out re-ran every hit (~0.5s action floor).
     */
    public static function queryResultPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'search.query_result',
            pool: 'search',
            scope: 'channel',
            vary: ['lang', 'currency', 'area'],
            dependencies: ['catalog', 'config'],
            freshTtlSeconds: 120,
            staleTtlSeconds: 900,
            singleFlightWaitMs: 2500,
            allowEmptyResult: true,
        );
    }

    public static function queryResultLogicalKey(
        string $q,
        string $type,
        int $page,
        int $pageSize,
        string $engine,
        string $area,
        bool $autocomplete = false,
    ): string {
        $normQ = mb_strtolower(trim($q));
        $type = $type === '' ? 'all' : $type;
        $area = strtolower(trim($area));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            $area = 'frontend';
        }

        return 'search.query_result.v1.'
            . hash('xxh3', $normQ)
            . '.' . $type
            . '.' . max(1, $page)
            . '.' . max(1, $pageSize)
            . '.' . trim($engine)
            . '.' . $area
            . '.' . ($autocomplete ? 'ac' : 'page');
    }

    public function notifyHotWordsChanged(
        int $websiteId,
        int $storeId = 0,
        int $channelId = 0,
        string $reason = 'hot_words_changed',
    ): void {
        $websiteId = max(0, $websiteId);
        $storeId = max(0, $storeId);
        $channelId = max(0, $channelId);

        if ($channelId > 0) {
            $this->forgetHotWords($websiteId, $storeId, $channelId);
        } else {
            $this->hotCache->purgeProcessCacheForLogicalKey('search.hot_words.' . $websiteId);
        }

        $this->events->dispatch(self::EVENT_HOT_WORDS_CHANGED, [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'reason' => $reason,
        ]);
    }

    public function forgetHotWords(int $websiteId, int $storeId, int $channelId): void
    {
        $logicalKey = HotWordsService::logicalCacheKey($websiteId, $storeId, $channelId);
        $this->hotCache->purgeProcessCacheForLogicalKey($logicalKey);
        $this->hotCache->forget(
            HotWordsService::cachePool(),
            $logicalKey,
            ['website' => true],
        );
    }
}
