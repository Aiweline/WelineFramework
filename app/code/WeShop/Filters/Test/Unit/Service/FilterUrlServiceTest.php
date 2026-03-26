<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Service\FilterUrlService;
use Weline\Framework\Http\Request;

class FilterUrlServiceTest extends TestCase
{
    public function testGetFilterParamsExcludesReservedKeysAndSplitsCommaValues(): void
    {
        $service = new FilterUrlService($this->createRequest(
            '/catalog/category/view?id=7&sort=price&brand=nike,adidas&price=100-200',
            [
                'id' => 7,
                'sort' => 'price',
                'brand' => 'nike,adidas',
                'price' => '100-200',
            ]
        ));

        $params = $service->getFilterParams();

        $this->assertSame(['nike', 'adidas'], $params['brand']);
        $this->assertSame('100-200', $params['price']);
        $this->assertArrayNotHasKey('id', $params);
        $this->assertArrayNotHasKey('sort', $params);
    }

    public function testClearAllUrlDropsPageButKeepsReservedContext(): void
    {
        $service = new FilterUrlService($this->createRequest(
            '/catalog/category/view?handle=bags&page=3&sort=price&brand=nike,adidas',
            [
                'handle' => 'bags',
                'page' => 3,
                'sort' => 'price',
                'brand' => 'nike,adidas',
            ]
        ));

        $clearAllUrl = $service->getClearAllUrl(7);
        $parts = parse_url($clearAllUrl);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('/catalog/category/view', $parts['path'] ?? '');
        $this->assertSame('bags', $query['handle'] ?? null);
        $this->assertSame('price', $query['sort'] ?? null);
        $this->assertArrayNotHasKey('page', $query);
        $this->assertArrayNotHasKey('brand', $query);
    }

    public function testRemoveFilterUrlResetsPageAndPreservesOtherValues(): void
    {
        $service = new FilterUrlService($this->createRequest(
            '/catalog/category/view?handle=bags&page=2&brand=nike,adidas&size=l',
            [
                'handle' => 'bags',
                'page' => 2,
                'brand' => 'nike,adidas',
                'size' => 'l',
            ]
        ));

        $removeUrl = $service->getRemoveFilterUrl('brand', 'nike');
        $parts = parse_url($removeUrl);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('adidas', $query['brand'] ?? null);
        $this->assertSame('l', $query['size'] ?? null);
        $this->assertSame('bags', $query['handle'] ?? null);
        $this->assertArrayNotHasKey('page', $query);
    }

    private function createRequest(string $uri, array $query): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQuery')->willReturnCallback(
            static function (string $key = '', mixed $default = null) use ($query) {
                if ($key === '') {
                    return $query;
                }

                return $query[$key] ?? $default;
            }
        );

        return $request;
    }
}
