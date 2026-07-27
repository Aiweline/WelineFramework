<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\ServerInstanceManager;

class CacheFlushedObserver implements ObserverInterface
{
    private const REQUEST_NOTIFIED_KEY = 'server.cache_flushed.notified.v1';

    public function execute(Event &$event): void
    {
        if (RequestContext::get(self::REQUEST_NOTIFIED_KEY, false) === true) {
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        if (!$manager->hasRunningWorkers()) {
            return;
        }

        RequestContext::set(self::REQUEST_NOTIFIED_KEY, true);
        ObjectManager::getInstance(BroadcastControlDispatchService::class)->cacheClear();
    }

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::REQUEST_NOTIFIED_KEY);
    }
}
