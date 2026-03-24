<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Service\SearchPageDataService;
use WeShop\Search\Service\SearchService;

class SearchPageDataServiceTest extends TestCase
{
    public function testBuildSkipsProductSearchForEmptyKeyword(): void
    {
        $searchService = $this->createMock(SearchService::class);
        $searchService->expects($this->never())->method('searchProducts');
        $searchService->expects($this->once())
            ->method('getPopularKeywords')
            ->with(10)
            ->willReturn([
                ['keyword' => 'bag', 'count' => 8],
            ]);

        $service = new SearchPageDataService($searchService);
        $result = $service->build('   ', [], 1, 20);

        $this->assertFalse($result['has_keyword']);
        $this->assertSame([], $result['products']);
        $this->assertSame('bag', $result['popular_keywords'][0]['keyword']);
    }

    public function testBuildReturnsSearchSummaryAndFilters(): void
    {
        $searchService = $this->createMock(SearchService::class);
        $searchService->expects($this->once())
            ->method('searchProducts')
            ->with('travel bag', ['order_by' => 'price', 'order_dir' => 'asc'], 2, 12)
            ->willReturn([
                'items' => [['product_id' => 15, 'name' => 'Travel Bag']],
                'total' => 15,
                'pagination' => '<nav>...</nav>',
                'engine' => 'meilisearch',
            ]);
        $searchService->expects($this->once())
            ->method('getPopularKeywords')
            ->with(10)
            ->willReturn([]);

        $service = new SearchPageDataService($searchService);
        $result = $service->build('travel bag', ['order_by' => 'price', 'order_dir' => 'asc'], 2, 12);

        $this->assertTrue($result['has_keyword']);
        $this->assertSame('Travel Bag', $result['products'][0]['name']);
        $this->assertSame(13, $result['search_summary']['from']);
        $this->assertSame(15, $result['search_summary']['to']);
        $this->assertSame('Showing 13-15 of 15 results', $result['search_summary']['label']);
        $this->assertSame('Sort', $result['active_filters'][0]['label']);
        $this->assertSame('price ASC', $result['active_filters'][0]['value']);
    }
}
