<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 添加到愿望清单像素观察者
 */
class AddToWishlistPixel extends Observer implements ObserverInterface
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

        $this->pixelDispatcher->dispatch('AddToWishlist', [
            'product_id' => $eventData['product_id'] ?? 0,
        ]);
    }
}
