<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Observer\AddToCartPixel;
use WeShop\Analytics\Observer\AddToWishlistPixel;
use WeShop\Analytics\Observer\OrderPaidPixel;
use WeShop\Analytics\Service\PixelDispatcher;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

class EventDrivenPixelObserversTest extends TestCase
{
    public function testAddToCartPixelDispatchesNormalizedEventData(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('dispatch')
            ->with('AddToCart', [
                'product_id' => 18,
                'quantity' => 2,
                'price' => 49.9,
            ]);

        $observer = new AddToCartPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'product_id' => 18,
            'quantity' => 2,
            'price' => 49.9,
        ]]);

        $observer->execute($event);
    }

    public function testAddToWishlistPixelDispatchesProductId(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('dispatch')
            ->with('AddToWishlist', [
                'product_id' => 26,
            ]);

        $observer = new AddToWishlistPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'product_id' => 26,
        ]]);

        $observer->execute($event);
    }

    public function testOrderPaidPixelDispatchesPurchasePayload(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Purchase', [
                'order_id' => 91,
                'order_number' => 'WS000091',
                'total' => 188.6,
            ]);

        $observer = new OrderPaidPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'order' => new DataObject([
                'order_id' => 91,
                'increment_id' => 'WS000091',
                'total' => 188.6,
            ]),
        ]]);

        $observer->execute($event);
    }

    public function testOrderPaidPixelSkipsDispatchWhenOrderMissing(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->never())->method('dispatch');

        $observer = new OrderPaidPixel($pixelDispatcher);
        $event = new Event(['data' => []]);

        $observer->execute($event);
    }

    public function testOrderPaidPixelSkipsDispatchWhenOrderDoesNotSupportDataAccess(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->never())->method('dispatch');

        $observer = new OrderPaidPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'order' => new \stdClass(),
        ]]);

        $observer->execute($event);
    }
}
