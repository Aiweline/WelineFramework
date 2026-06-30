<?php

declare(strict_types=1);

namespace Weline\Benchmark\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CountingObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('count', (int)$event->getData('count') + 1);
    }
}
