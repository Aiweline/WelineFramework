<?php

declare(strict_types=1);

namespace Weline\Search\Service;

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
