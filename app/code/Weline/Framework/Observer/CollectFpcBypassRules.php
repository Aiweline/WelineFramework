<?php

declare(strict_types=1);

namespace Weline\Framework\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Fpc\FpcBypassCollector;
use Weline\Framework\Manager\ObjectManager;

/** setup:upgrade 收集 FPC bypass 侧车。 */
final class CollectFpcBypassRules implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var FpcBypassCollector $collector */
        $collector = ObjectManager::getInstance(FpcBypassCollector::class);
        $collector->collectAndPersist();
    }
}
