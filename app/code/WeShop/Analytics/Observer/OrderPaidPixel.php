<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 订单支付像素观察者
 */
class OrderPaidPixel implements ObserverInterface
{
    public function __construct(
        private readonly PixelDispatcher $pixelDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        $order = is_array($eventData) ? ($eventData['order'] ?? null) : null;

        if (!$order || !is_object($order) || !method_exists($order, 'getData')) {
            return;
        }

        $this->pixelDispatcher->dispatch('Purchase', [
            'order_id' => $order->getData('order_id') ?? 0,
            'order_number' => $order->getData('increment_id') ?? '',
            'total' => $order->getData('total') ?? 0,
        ]);
    }
}
