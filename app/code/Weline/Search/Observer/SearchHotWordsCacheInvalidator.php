<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeRuntimeCacheResetInterface;

/**
 * Clears theme/widget runtime caches after hot-word scope cache invalidation.
 */
final class SearchHotWordsCacheInvalidator implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (max(0, (int)($event->getData('website_id') ?? 0)) <= 0) {
            return;
        }

        try {
            ObjectManager::getInstance(ThemeRuntimeCacheResetInterface::class)->clearRuntimeCaches();
        } catch (\Throwable) {
            // 无主题模块时无运行时缓存可清。
        }
    }
}
