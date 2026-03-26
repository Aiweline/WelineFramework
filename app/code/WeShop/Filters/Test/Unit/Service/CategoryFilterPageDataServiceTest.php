<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Service\CategoryFilterPageDataService;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;

class CategoryFilterPageDataServiceTest extends TestCase
{
    public function testBuildReturnsEmptyPayloadWhenNoProductsExist(): void
    {
        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->never())->method('getFilterResult');

        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->expects($this->once())
            ->method('getClearAllUrl')
            ->with(15)
            ->willReturn('/catalog/category/view?id=15');

        $service = new CategoryFilterPageDataService($filterService, $urlService);
        $payload = $service->build(15, []);

        $this->assertSame(15, $payload['category_id']);
        $this->assertSame([], $payload['filters']);
        $this->assertSame([], $payload['applied_filters']);
        $this->assertSame([], $payload['filtered_product_ids']);
        $this->assertSame('/catalog/category/view?id=15', $payload['clear_all_url']);
    }

    public function testBuildReturnsFilterPayloadForCategoryProducts(): void
    {
        $filterResult = $this->createMock(FilterResultInterface::class);
        $filterResult->method('getFilters')->willReturn([
            ['code' => 'brand', 'name' => 'Brand'],
        ]);
        $filterResult->method('getAppliedFilters')->willReturn([
            ['filter_code' => 'brand', 'value' => 'nike', 'label' => 'Nike'],
        ]);
        $filterResult->method('getClearAllUrl')->willReturn('/catalog/category/view?id=4');
        $filterResult->method('getProductIds')->willReturn([22, 23]);

        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->once())
            ->method('getFilterResult')
            ->with(4, [21, 22, 23], ['brand' => ['nike']])
            ->willReturn($filterResult);

        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->expects($this->once())->method('getClearAllUrl')->with(4)->willReturn('/catalog/category/view?id=4');
        $urlService->expects($this->once())->method('getFilterParams')->willReturn(['brand' => ['nike']]);

        $service = new CategoryFilterPageDataService($filterService, $urlService);
        $payload = $service->build(4, [21, 22, 23]);

        $this->assertSame(4, $payload['category_id']);
        $this->assertCount(1, $payload['filters']);
        $this->assertCount(1, $payload['applied_filters']);
        $this->assertSame([22, 23], $payload['filtered_product_ids']);
        $this->assertSame('/catalog/category/view?id=4', $payload['clear_all_url']);
    }
}
