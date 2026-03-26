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
        $searchService->expects($this->never())->method('browseProducts');
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
            ->method('browseProducts')
            ->with('travel bag', ['order_by' => 'price', 'order_dir' => 'asc'], 2, 12, 'default', [], true)
            ->willReturn([
                'items' => [['product_id' => 15, 'name' => 'Travel Bag']],
                'total' => 15,
                'pagination' => ['page' => 2, 'page_size' => 12, 'total' => 15, 'from' => 13, 'to' => 15],
                'pagination_html' => '<nav>...</nav>',
                'engine' => 'meilisearch',
                'facets' => [
                    [
                        'code' => 'attr_color',
                        'name' => 'Color',
                        'options' => [['value' => 'red', 'label' => 'Red', 'count' => 3]],
                    ],
                ],
                'applied_filters' => [
                    [
                        'filter_code' => 'attr_color',
                        'filter_name' => 'Color',
                        'value' => 'red',
                        'label' => 'Red',
                        'remove_url' => '/search?q=travel+bag',
                    ],
                ],
                'clear_all_url' => '/search?q=travel+bag',
            ]);
        $searchService->expects($this->once())
            ->method('getPopularKeywords')
            ->with(10)
            ->willReturn([]);

        $service = new SearchPageDataService($searchService);
        $result = $service->build('travel bag', ['order_by' => 'price', 'order_dir' => 'asc'], 2, 12);

        $this->assertTrue($result['has_keyword']);
        $this->assertSame('Travel Bag', $result['products'][0]['name']);
        $this->assertSame('<nav>...</nav>', $result['pagination']);
        $this->assertSame(15, $result['pagination_data']['total']);
        $this->assertSame(13, $result['search_summary']['from']);
        $this->assertSame(15, $result['search_summary']['to']);
        $this->assertSame('Showing 13-15 of 15 results', $result['search_summary']['label']);
        $this->assertCount(2, $result['active_filters']);
        $this->assertSame('Color', $result['active_filters'][0]['label']);
        $this->assertSame('Red', $result['active_filters'][0]['value']);
        $this->assertSame('Sort', $result['active_filters'][1]['label']);
        $this->assertSame('price ASC', $result['active_filters'][1]['value']);
    }
}
