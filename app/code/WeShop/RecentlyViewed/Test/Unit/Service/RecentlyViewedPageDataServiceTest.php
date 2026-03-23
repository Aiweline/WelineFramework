<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\RecentlyViewed\Service\RecentlyViewedPageDataService;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;

class RecentlyViewedPageDataServiceTest extends TestCase
{
    public function testBuildMapsRecentlyViewedItemsAndRecommendations(): void
    {
        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->once())
            ->method('getRecentlyViewed')
            ->with(7, 12)
            ->willReturn([
                [
                    'view_id' => 11,
                    'product_id' => 501,
                    'viewed_at' => '2026-03-23 10:00:00',
                    'product' => [
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'price' => 99.9,
                        'sku' => 'BP-001',
                    ],
                ],
                [
                    'view_id' => 12,
                    'product_id' => 502,
                    'viewed_at' => '2026-03-23 09:30:00',
                    'product' => [
                        'name' => 'Passport Holder',
                        'image' => '/media/passport.jpg',
                        'price' => 29.9,
                        'sku' => 'PH-002',
                    ],
                ],
            ]);
        $recentlyViewedService->expects($this->once())
            ->method('getRecentlyViewedCount')
            ->with(7)
            ->willReturn(2);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([501, 502], 4)
            ->willReturn([
                ['product_id' => 701, 'name' => 'Luggage Tag', 'price' => 12.5],
            ]);

        $service = new RecentlyViewedPageDataService($recentlyViewedService, $recommendationService);
        $result = $service->build(7);

        $this->assertSame(2, $result['recently_viewed_count']);
        $this->assertSame('Travel Backpack', $result['recently_viewed_items'][0]['name']);
        $this->assertSame('2026-03-23 10:00:00', $result['recently_viewed_items'][0]['viewed_at']);
        $this->assertSame(701, $result['recommendations'][0]['product_id']);
    }
}
