<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Extends\Module\Weline_Framework\Query\SearchQueryProvider;
use WeShop\Search\Service\SearchIndexer;
use WeShop\Search\Service\SearchService;

class SearchQueryProviderTest extends TestCase
{
    public function testSearchQueryProviderDelegatesSearchAndIndexOperations(): void
    {
        $searchService = $this->createMock(SearchService::class);
        $searchService->expects($this->once())
            ->method('searchProducts')
            ->with('apple', ['category_id' => 3], 2, 12, 'default')
            ->willReturn(['items' => [], 'total' => 0, 'engine' => 'opensearch']);
        $searchService->expects($this->once())
            ->method('browseProducts')
            ->with('apple', ['attr_color' => ['red']], 1, 24, 'default', [3, 4], true)
            ->willReturn(['items' => [['product_id' => 11]], 'total' => 1, 'engine' => 'opensearch']);
        $searchService->expects($this->once())
            ->method('getSearchSuggestions')
            ->with('apple', 5, 'default')
            ->willReturn([['text' => 'Apple Watch']]);

        $searchIndexer = $this->createMock(SearchIndexer::class);
        $searchIndexer->expects($this->once())
            ->method('rebuild')
            ->with('product', true, 100, 'default')
            ->willReturn(true);
        $searchIndexer->expects($this->once())
            ->method('indexEntity')
            ->with('product', '15', 'default')
            ->willReturn(true);
        $searchIndexer->expects($this->once())
            ->method('deleteEntity')
            ->with('product', '15', 'default')
            ->willReturn(true);
        $searchIndexer->expects($this->once())
            ->method('getProviderDescriptors')
            ->willReturn([['provider' => 'product']]);

        $provider = new SearchQueryProvider($searchService, $searchIndexer);

        $this->assertSame(
            ['items' => [], 'total' => 0, 'engine' => 'opensearch'],
            $provider->execute('search', ['keyword' => 'apple', 'filters' => ['category_id' => 3], 'page' => 2, 'page_size' => 12])
        );
        $this->assertSame(
            ['items' => [['product_id' => 11]], 'total' => 1, 'engine' => 'opensearch'],
            $provider->execute('browseProducts', [
                'keyword' => 'apple',
                'filters' => ['attr_color' => ['red']],
                'category_ids' => [3, 4],
                'include_facets' => true,
                'page' => 1,
                'page_size' => 24,
            ])
        );
        $this->assertSame(
            [['text' => 'Apple Watch']],
            $provider->execute('suggest', ['keyword' => 'apple', 'limit' => 5])
        );
        $this->assertTrue($provider->execute('rebuildIndex', ['provider' => 'product', 'force' => true]));
        $this->assertTrue($provider->execute('indexEntity', ['provider' => 'product', 'entity_id' => 15]));
        $this->assertTrue($provider->execute('deleteEntity', ['provider' => 'product', 'entity_id' => 15]));
        $this->assertSame([['provider' => 'product']], $provider->execute('providers'));
    }
}
