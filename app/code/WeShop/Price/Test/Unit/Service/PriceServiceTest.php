<?php

declare(strict_types=1);

namespace WeShop\Price\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use Weline\Currency\Service\CurrencyRateService;
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

    public function testResolveProductDataUsesCustomerSpecificPriceWhenCustomerMatches(): void
    {
        $service = $this->createService();

        $result = $service->resolveProductData([
            'product_id' => 13,
            'price' => 180.0,
            'customer_prices' => [
                ['customer_id' => 88, 'price' => 129.5],
                ['customer_id' => 99, 'price' => 139.5],
            ],
        ], 88);

        $this->assertSame(129.5, $result['price']);
        $this->assertSame(180.0, $result['original_price']);
        $this->assertSame(129.5, $result['special_price']);
        $this->assertTrue($result['has_discount']);
    }

    public function testResolveProductDataUsesBestEligibleTierPriceForQuantity(): void
    {
        $service = $this->createService();

        $result = $service->resolveProductData([
            'product_id' => 14,
            'price' => 100.0,
            'tier_prices' => [
                ['qty' => 3, 'price' => 92.0],
                ['qty' => 5, 'price' => 85.0],
            ],
        ], null, 5);

        $this->assertSame(85.0, $result['price']);
        $this->assertSame(100.0, $result['original_price']);
        $this->assertSame(85.0, $result['special_price']);
        $this->assertSame(15.0, $result['discount_amount']);
    }

    public function testResolveProductDataAllowsEventsToAdjustFinalPriceBeforeSanitizing(): void
    {
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->once())
            ->method('dispatch')
            ->with(
                'WeShop_Price::calculate_price',
                $this->callback(function (array $eventData): bool {
                    $eventData['price'] = 80.0;
                    $eventData['original_price'] = 120.0;
                    $eventData['special_price'] = 80.0;

                    return true;
                })
            );

        $service = $this->createService($eventsManager);
        $result = $service->resolveProductData([
            'product_id' => 15,
            'price' => 120.0,
        ]);

        $this->assertSame(80.0, $result['price']);
        $this->assertSame(120.0, $result['original_price']);
        $this->assertSame(80.0, $result['special_price']);
        $this->assertTrue($result['has_discount']);
    }

    public function testCalculatePriceRejectsInvalidProductId(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $service->calculatePrice(0);
    }

    public function testFormatPriceUsesCurrencyRateService(): void
    {
        $currencyRateService = $this->createMock(CurrencyRateService::class);
        $currencyRateService->expects($this->once())
            ->method('format')
            ->with(99.5, null, 'USD')
            ->willReturn('$12.44');

        $service = $this->createService(null, $currencyRateService);

        $this->assertSame('$12.44', $service->formatPrice(99.5, 'usd'));
    }

    private function createService(
        ?EventsManager $eventsManager = null,
        ?CurrencyRateService $currencyRateService = null
    ): PriceService
    {
        $product = $this->createMock(Product::class);
        if ($eventsManager === null) {
            $eventRegistry = $this->createMock(EventRegistryInterface::class);
            $eventRegistry->method('hasObservers')->willReturn(false);
            $eventRegistry->method('getRegistry')->willReturn(['events' => []]);
            $eventRegistry->method('matchPattern')->willReturn(false);
            $eventsManager = new EventsManager(
                $this->createMock(XmlReader::class),
                $eventRegistry
            );
        }

        return new PriceService($product, $eventsManager, $currencyRateService);
    }
}
