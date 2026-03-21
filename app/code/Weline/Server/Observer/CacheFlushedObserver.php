<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\ServerInstanceManager;

class CacheFlushedObserver implements ObserverInterface
{
    private static bool $notifiedInRequest = false;

    public function execute(Event &$event): void
    {
        if (self::$notifiedInRequest) {
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        if (!$manager->hasRunningWorkers()) {
            return;
        }

        self::$notifiedInRequest = true;
        ObjectManager::getInstance(BroadcastControlDispatchService::class)->cacheClear();
    }

    public static function resetRequestState(): void
    {
        self::$notifiedInRequest = false;
    }
}
