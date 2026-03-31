<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 添加到愿望清单像素观察者
 */
class AddToWishlistPixel implements ObserverInterface
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

        $this->pixelDispatcher->dispatch('AddToWishlist', [
            'product_id' => $eventData['product_id'] ?? 0,
        ]);
    }
}
