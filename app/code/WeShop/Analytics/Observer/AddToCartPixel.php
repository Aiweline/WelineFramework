<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 添加到购物车像素观察者
 */
class AddToCartPixel extends Observer implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $eventData = $this->getEvent()->getData();
        
        /** @var PixelDispatcher $pixelDispatcher */
        $pixelDispatcher = ObjectManager::getInstance(PixelDispatcher::class);
        
        $pixelDispatcher->dispatch('AddToCart', [
            'product_id' => $eventData['product_id'] ?? 0,
            'quantity' => $eventData['quantity'] ?? 1,
            'price' => $eventData['price'] ?? 0,
        ]);
    }
}
