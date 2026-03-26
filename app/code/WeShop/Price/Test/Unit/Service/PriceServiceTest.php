<?php

declare(strict_types=1);

namespace WeShop\Price\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Event\EventsManager;

class PriceServiceTest extends TestCase
{
    public function testResolveProductDataKeepsBasePriceWhenNoDiscountExists(): void
    {
        $service = $this->createService();

        $result = $service->resolveProductData([
            'product_id' => 10,
            'price' => 129.5,
        ]);

        $this->assertSame(129.5, $result['price']);
        $this->assertSame(129.5, $result['original_price']);
        $this->assertNull($result['special_price']);
        $this->assertFalse($result['has_discount']);
        $this->assertSame(0, $result['discount_percent']);
    }

    public function testResolveProductDataUsesLowerSpecialPriceAsFinalPrice(): void
    {
        $service = $this->createService();

        $result = $service->resolveProductData([
            'product_id' => 11,
            'price' => 200.0,
            'special_price' => 149.99,
        ]);

        $this->assertSame(149.99, $result['price']);
        $this->assertSame(200.0, $result['original_price']);
        $this->assertSame(149.99, $result['special_price']);
        $this->assertTrue($result['has_discount']);
        $this->assertSame(25, $result['discount_percent']);
    }

    public function testResolveProductDataIgnoresInvalidSpecialPrice(): void
    {
        $service = $this->createService();

        $result = $service->resolveProductData([
            'product_id' => 12,
            'price' => 80.0,
            'special_price' => 99.0,
            'sale_price' => 'abc',
        ]);

        $this->assertSame(80.0, $result['price']);
        $this->assertSame(80.0, $result['original_price']);
        $this->assertNull($result['special_price']);
        $this->assertFalse($result['has_discount']);
    }

    private function createService(): PriceService
    {
        $product = $this->createMock(Product::class);
        $eventRegistry = $this->createMock(EventRegistryInterface::class);
        $eventRegistry->method('hasObservers')->willReturn(false);
        $eventRegistry->method('getRegistry')->willReturn(['events' => []]);
        $eventRegistry->method('matchPattern')->willReturn(false);
        $eventsManager = new EventsManager(
            $this->createMock(XmlReader::class),
            $eventRegistry
        );

        return new PriceService($product, $eventsManager);
    }
}
