<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 订单创建像素追踪观察者
 */
class OrderCreatedPixel implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data->getData('order');
        
        if (!$order) {
            return;
        }
        
        /** @var PixelDispatcher $dispatcher */
        $dispatcher = ObjectManager::getInstance(PixelDispatcher::class);
        
        $dispatcher->track('begin_checkout', [
            'user_id' => $order->getCustomerId(),
            'module' => 'WeShop_Order',
            'name' => 'order_created',
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'value' => $order->getTotal(),
            'currency' => $order->getCurrency() ?? 'CNY',
            'additional' => [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
            ],
        ]);
    }
}
