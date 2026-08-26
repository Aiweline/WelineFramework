<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\SlotRendererService;

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

        ControllerFetchFileBefore::clearRuntimeCache();
        SlotRendererService::clearProcessMemoryCache();
    }
}
