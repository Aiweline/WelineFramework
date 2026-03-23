<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\Wishlist\Service\WishlistPageDataService;
use WeShop\Wishlist\Service\WishlistService;

class WishlistPageDataServiceTest extends TestCase
{
    public function testBuildMapsWishlistItemsAndRecommendations(): void
    {
        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlist')
            ->with(7)
            ->willReturn([
                [
                    'wishlist_id' => 11,
                    'product_id' => 501,
                    'product' => [
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'price' => 99.9,
                    ],
                ],
                [
                    'wishlist_id' => 12,
                    'product_id' => 502,
                    'product' => [
                        'name' => 'Packing Cube',
                        'image' => '/media/cube.jpg',
                        'price' => 19.9,
                    ],
                ],
            ]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([501, 502], 4)
            ->willReturn([
                ['product_id' => 601, 'name' => 'Luggage Tag', 'price' => 12.5],
            ]);

        $service = new WishlistPageDataService($wishlistService, $recommendationService);
        $result = $service->build(7);

        $this->assertSame(2, $result['wishlist_count']);
        $this->assertSame('Travel Backpack', $result['wishlist_items'][0]['name']);
        $this->assertSame(601, $result['recommendations'][0]['product_id']);
    }
}
