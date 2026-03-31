<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Model\Compare;
use WeShop\Compare\Service\CompareAdminPageDataService;
use WeShop\Compare\Service\CompareService;

class CompareAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataReturnsExpectedStructure(): void
    {
        $compareService = $this->createMock(CompareService::class);
        $service = new CompareAdminPageDataService($compareService);

        $result = $service->getPageData(1, 20, [], 0);

        $this->assertArrayHasKey('compare_items', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('filters', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('editing_id', $result);
    }

    public function testGetPageDataWithFilters(): void
    {
        $compareService = $this->createMock(CompareService::class);
        $service = new CompareAdminPageDataService($compareService);

        $filters = [
            'customer_id' => '7',
            'product_id' => '101',
        ];

        $result = $service->getPageData(1, 20, $filters, 0);

        $this->assertSame($filters, $result['filters']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertSame(20, $result['pagination']['page_size']);
    }

    public function testPaginationCalculation(): void
    {
        $compareService = $this->createMock(CompareService::class);
        $service = new CompareAdminPageDataService($compareService);

        $result = $service->getPageData(2, 10, [], 0);

        $this->assertSame(2, $result['pagination']['page']);
        $this->assertSame(10, $result['pagination']['page_size']);
    }

    public function testDeleteReturnsBool(): void
    {
        $compareService = $this->createMock(CompareService::class);
        $service = new CompareAdminPageDataService($compareService);

        $result = $service->delete(0);

        $this->assertIsBool($result);
    }
}
