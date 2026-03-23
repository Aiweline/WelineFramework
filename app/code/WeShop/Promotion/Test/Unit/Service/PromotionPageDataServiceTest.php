<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Service\ProductService;
use WeShop\Promotion\Service\PromotionPageDataService;
use Weline\Framework\Http\Url;

class PromotionPageDataServiceTest extends TestCase
{
    public function testBuildReturnsNormalizedProductsAndLinks(): void
    {
        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProducts')
            ->with(
                $this->callback(static fn(array $filters): bool => ($filters['order_by'] ?? '') === 'price'),
                1,
                24
            )
            ->willReturn([
                'items' => [[
                    'product_id' => 11,
                    'name' => 'Deal Product',
                    'short_description' => 'Short intro',
                    'image' => '/image.png',
                    'price' => 80,
                    'original_price' => 100,
                    'stock' => 8,
                ]],
                'total' => 1,
            ]);

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnMap([
            ['promotion', null, '/promotion'],
            ['promotion/deals', null, '/promotion/deals'],
            ['promotion/sale', null, '/promotion/sale'],
            ['promotion/coupon/apply', null, '/promotion/coupon/apply'],
            ['product/view', ['id' => 11], '/product/view?id=11'],
        ]);

        $service = new PromotionPageDataService($productService, $url);
        $result = $service->build('sale', 1, 24);

        $this->assertSame(1, $result['total']);
        $this->assertSame('sale', $result['page_type']);
        $this->assertSame('/promotion/deals', $result['deals_url']);
        $this->assertSame('/promotion/sale', $result['sale_url']);
        $this->assertSame('/promotion/coupon/apply', $result['coupon_apply_url']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(11, $result['items'][0]['product_id']);
        $this->assertSame(20, $result['items'][0]['discount_percent']);
        $this->assertTrue($result['items'][0]['in_stock']);
        $this->assertSame('/product/view?id=11', $result['items'][0]['url']);
        $this->assertNotEmpty($result['promotions']);
    }
}
