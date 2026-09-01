<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\DevRelayDispatcher;

final class WebhookInboxReceivedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        ObjectManager::getInstance(DevRelayDispatcher::class)->handleEvent($event);
    }
}
