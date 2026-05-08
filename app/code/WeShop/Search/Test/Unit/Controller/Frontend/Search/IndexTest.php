<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Controller\Frontend\Search;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Controller\Frontend\Search\Index;
use WeShop\Search\Service\SearchPageDataService;
use Weline\Framework\Http\Request;

class IndexTest extends TestCase
{
    public function testIndexAssignsSearchPageData(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'q' => ' travel bag ',
            'page' => '2',
            'page_size' => '12',
            'order_by' => 'price',
            'order_dir' => 'asc',
        ]));

        $pageDataService = $this->createMock(SearchPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with('travel bag', ['order_by' => 'price', 'order_dir' => 'asc'], 2, 12)
            ->willReturn([
                'keyword' => 'travel bag',
                'products' => [],
                'search_summary' => ['label' => 'summary'],
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageDataService])
            ->onlyMethods(['assign', 'renderPage'])
            ->getMock();

        $controller->expects($this->exactly(3))->method('assign');
        $controller->expects($this->once())->method('renderPage')->willReturn('page');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('page', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = ''): mixed => $params[$key] ?? $default;
    }
}
