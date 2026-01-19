<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 订单支付像素观察者
 */
class OrderPaidPixel extends Observer implements ObserverInterface
{
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
        
        /** @var PixelDispatcher $pixelDispatcher */
        $pixelDispatcher = ObjectManager::getInstance(PixelDispatcher::class);
        
        $pixelDispatcher->dispatch('Purchase', [
            'order_id' => $order->getData('order_id') ?? 0,
            'order_number' => $order->getData('increment_id') ?? '',
            'total' => $order->getData('total') ?? 0,
        ]);
    }
}
