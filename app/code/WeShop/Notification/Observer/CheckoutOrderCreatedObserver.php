<?php

declare(strict_types=1);

namespace WeShop\Notification\Observer;

use WeShop\Notification\Service\CustomerNotificationRouter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CheckoutOrderCreatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly CustomerNotificationRouter $router
    ) {
    }

    public function execute(Event &$event): void
    {
        $order = $event->getData('order');
        $incrementId = $order && method_exists($order, 'getData')
            ? (string) ($order->getData('increment_id') ?? '')
            : '';

        $this->router->routeOrderNotification([
            'customer_id' => (int) ($event->getData('customer_id') ?? 0),
            'is_guest_checkout' => (bool) ($event->getData('is_guest_checkout') ?? false),
            'guest_email' => (string) ($event->getData('guest_email') ?? ''),
            'notification_channels' => is_array($event->getData('notification_channels')) ? $event->getData('notification_channels') : [],
            'title' => $incrementId !== ''
                ? (string) __('订单 {1} 已提交', $incrementId)
                : (string) __('订单已提交'),
            'content' => (string) __('我们已收到您的订单，后续支付、发货和售后状态会按您选择的通知渠道同步。'),
            'metadata' => [
                'order_id' => $order && method_exists($order, 'getId') ? (int) $order->getId() : 0,
                'increment_id' => $incrementId,
                'checkout_mode' => (string) ($event->getData('checkout_mode') ?? ''),
            ],
        ]);
    }
}
