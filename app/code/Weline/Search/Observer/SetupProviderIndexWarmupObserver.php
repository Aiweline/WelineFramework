<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderIndexService;
use Weline\Search\Service\SearchProviderRegistry;

/** Warm up empty provider indexes after setup:upgrade. */
final class SetupProviderIndexWarmupObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var SearchProviderIndexService $indexService */
        $indexService = ObjectManager::getInstance(SearchProviderIndexService::class);
        /** @var SearchProviderRegistry $registry */
        $registry = ObjectManager::getInstance(SearchProviderRegistry::class);

        foreach ($registry->all() as $code => $provider) {
            if ($indexService->isIndexed($code, 0)) {
                continue;
            }
            $indexService->rebuild($code, 0);
        }
    }
}
