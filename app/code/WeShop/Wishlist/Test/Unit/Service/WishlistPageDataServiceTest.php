<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\Wishlist\Service\WishlistPageDataService;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Http\Url;

class WishlistPageDataServiceTest extends TestCase
{
    public function testBuildMapsWishlistItemsRecommendationsAndUrls(): void
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
                        'product_id' => 501,
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'price' => 99.9,
                    ],
                ],
                [
                    'wishlist_id' => 12,
                    'product_id' => 502,
                    'product' => [
                        'product_id' => 502,
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

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnMap([
            ['wishlist', null, '/wishlist'],
            ['catalog/category', null, '/catalog/category'],
            ['wishlist/remove', null, '/wishlist/remove'],
            ['product/view', ['id' => 501], '/product/view?id=501'],
            ['product/view', ['id' => 502], '/product/view?id=502'],
            ['product/view', ['id' => 601], '/product/view?id=601'],
        ]);

        $service = new WishlistPageDataService($wishlistService, $recommendationService, $url);
        $result = $service->build(7);

        $this->assertSame(2, $result['wishlist_count']);
        $this->assertSame('/wishlist', $result['wishlist_url']);
        $this->assertSame('/catalog/category', $result['browse_url']);
        $this->assertSame('/wishlist/remove', $result['remove_url']);
        $this->assertSame('Travel Backpack', $result['wishlist_items'][0]['name']);
        $this->assertSame('/product/view?id=501', $result['wishlist_items'][0]['product_url']);
        $this->assertSame('/product/view?id=601', $result['recommendations'][0]['product_url']);
        $this->assertSame(601, $result['recommendations'][0]['product_id']);
    }

    public function testBuildAccountQuickLinkReturnsCountAndRoute(): void
    {
        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlistCount')
            ->with(7)
            ->willReturn(3);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('wishlist')
            ->willReturn('/wishlist');

        $service = new WishlistPageDataService($wishlistService, $recommendationService, $url);
        $result = $service->buildAccountQuickLink(7);

        $this->assertSame(3, $result['wishlist_count']);
        $this->assertSame('/wishlist', $result['wishlist_url']);
    }
}
