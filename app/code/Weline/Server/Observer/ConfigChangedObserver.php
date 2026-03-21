<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\ServerInstanceManager;

class ConfigChangedObserver implements ObserverInterface
{
    private const RELOAD_TRIGGER_MODULES = [
        'Weline_Server',
        'Weline_Framework',
    ];

    public function execute(Event &$event): void
    {
        $module = (string)($event->getData('module') ?? '');
        if (!$this->shouldReload($module)) {
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        if (!$manager->hasRunningWorkers()) {
            return;
        }

        ObjectManager::getInstance(BroadcastControlDispatchService::class)->cacheClear();
    }

    private function shouldReload(string $module): bool
    {
        foreach (self::RELOAD_TRIGGER_MODULES as $prefix) {
            if (\str_starts_with($module, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
