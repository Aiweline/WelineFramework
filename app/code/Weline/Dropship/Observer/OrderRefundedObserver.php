<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderRefundedObserver extends OrderCancelledObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        parent::execute($event);
    }
}
