<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Service\CartPageDataService;
use WeShop\Cart\Service\CartService;
use WeShop\Product\Service\ProductRecommendationService;

class CartPageDataServiceTest extends TestCase
{
    public function testBuildMapsCartDataForDefaultThemeAndRecommendationConsumers(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('getCartItems')
            ->with(12)
            ->willReturn([
                [
                    'cart_id' => 5,
                    'product_id' => 101,
                    'quantity' => 2,
                    'price' => 12.5,
                    'option' => 'Blue / Large',
                    'product' => [
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'stock' => 8,
                    ],
                ],
                'invalid-row',
            ]);
        $cartService->expects($this->once())
            ->method('calculateTotals')
            ->with(12)
            ->willReturn([
                'subtotal' => 25.0,
                'shipping' => 4.5,
                'discount' => 2.0,
                'tax' => 1.5,
                'total' => 29.0,
            ]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([101], 6)
            ->willReturn([
                ['product_id' => 301, 'name' => 'Packing Cube', 'price' => 9.9],
            ]);

        $service = new CartPageDataService($cartService, $recommendationService);
        $result = $service->build(12);

        $this->assertSame(2, $result['cart_count']);
        $this->assertSame(29.0, $result['cart_summary']['grand_total']);
        $this->assertSame(25.0, $result['cart_total']);
        $this->assertSame('Travel Backpack', $result['cart_items'][0]['name']);
        $this->assertSame(25.0, $result['cart_items'][0]['row_total']);
        $this->assertTrue((bool) ($result['cart_items'][0]['in_stock'] ?? false));
        $this->assertSame('Blue / Large', $result['cart_items'][0]['options'][0]['value']);
        $this->assertSame($result['recommendations'], $result['related_products']);
    }
}
