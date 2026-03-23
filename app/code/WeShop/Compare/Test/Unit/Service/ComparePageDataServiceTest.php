<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Service\ComparePageDataService;
use WeShop\Compare\Service\CompareService;
use WeShop\Product\Service\ProductRecommendationService;

class ComparePageDataServiceTest extends TestCase
{
    public function testBuildMapsCompareItemsAndRecommendations(): void
    {
        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->once())
            ->method('getCompareList')
            ->with(7)
            ->willReturn([
                [
                    'compare_id' => 11,
                    'product_id' => 501,
                    'product' => [
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'price' => 99.9,
                        'sku' => 'BP-001',
                        'brand' => 'WeShop',
                        'short_description' => 'Cabin-ready carry-on backpack.',
                        'stock' => 8,
                    ],
                ],
                [
                    'compare_id' => 12,
                    'product_id' => 502,
                    'product' => [
                        'name' => 'Passport Holder',
                        'image' => '/media/passport.jpg',
                        'price' => 29.9,
                        'sku' => 'PH-002',
                        'brand' => 'Traveler',
                        'short_description' => 'Slim passport wallet.',
                        'stock' => 0,
                    ],
                ],
            ]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([501, 502], 4)
            ->willReturn([
                ['product_id' => 701, 'name' => 'Packing Cube', 'price' => 19.9],
            ]);

        $service = new ComparePageDataService($compareService, $recommendationService);
        $result = $service->build(7);

        $this->assertSame(2, $result['compare_count']);
        $this->assertSame('Travel Backpack', $result['compare_items'][0]['name']);
        $this->assertSame('In stock', $result['compare_items'][0]['availability']);
        $this->assertSame('Out of stock', $result['compare_items'][1]['availability']);
        $this->assertSame(701, $result['recommendations'][0]['product_id']);
    }
}
