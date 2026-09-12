<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Dropship\Service\DropshipPushTerminalCompensationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class DropshipPushTerminalRefundObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DropshipPushTerminalCompensationService $svc */
        $svc = ObjectManager::getInstance(DropshipPushTerminalCompensationService::class);
        $svc->handle((array)$event->getData());
    }
}
