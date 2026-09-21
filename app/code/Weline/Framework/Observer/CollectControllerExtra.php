<?php

declare(strict_types=1);

namespace Weline\Framework\Observer;

use Weline\Framework\Controller\Extra\ExtraCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/** setup:upgrade 路由收集后写入 @Extra 侧车。 */
final class CollectControllerExtra implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var ExtraCollector $collector */
        $collector = ObjectManager::getInstance(ExtraCollector::class);
        $collector->collectAndPersist();
    }
}
