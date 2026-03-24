<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 订单支付像素观察者
 */
class OrderPaidPixel extends Observer implements ObserverInterface
{
    public function __construct(
        private readonly PixelDispatcher $pixelDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $eventData = $this->getEvent()->getData();
        $order = $eventData['order'] ?? null;

        if (!$order) {
            return;
        }

        $this->pixelDispatcher->dispatch('Purchase', [
            'order_id' => $order->getData('order_id') ?? 0,
            'order_number' => $order->getData('increment_id') ?? '',
            'total' => $order->getData('total') ?? 0,
        ]);
    }
}
