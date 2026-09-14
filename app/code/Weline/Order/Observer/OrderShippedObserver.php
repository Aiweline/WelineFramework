<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderMailNotifier;

class OrderShippedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }
        // Default true for legacy callers that omit the flag.
        if (array_key_exists('notify_customer', $data) && !$data['notify_customer']) {
            return;
        }
        try {
            /** @var OrderMailNotifier $notifier */
            $notifier = ObjectManager::getInstance(OrderMailNotifier::class);
            $notifier->notify($order, OrderMailNotifier::CHANNEL_SHIPPED, [
                'tracking_number' => (string)($data['tracking_number'] ?? ''),
                'carrier' => (string)($data['carrier'] ?? ''),
                'message' => (string)__('您的订单已发货'),
            ]);
        } catch (\Throwable $e) {
            w_log_error('OrderShippedObserver mail: ' . $e->getMessage(), [], 'order_mail');
        }
    }
}
