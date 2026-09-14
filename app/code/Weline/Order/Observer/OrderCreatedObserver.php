<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderMailNotifier;

class OrderCreatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }
        try {
            /** @var OrderMailNotifier $notifier */
            $notifier = ObjectManager::getInstance(OrderMailNotifier::class);
            $notifier->notify($order, OrderMailNotifier::CHANNEL_CREATED);
        } catch (\Throwable $e) {
            w_log_error('OrderCreatedObserver mail: ' . $e->getMessage(), [], 'order_mail');
        }
    }
}
