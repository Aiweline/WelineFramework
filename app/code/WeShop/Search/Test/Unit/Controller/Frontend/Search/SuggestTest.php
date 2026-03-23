<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Controller\Frontend\Search;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Controller\Frontend\Search\Suggest;
use WeShop\Search\Service\SearchService;
use Weline\Framework\Http\Request;

class SuggestTest extends TestCase
{
    public function testIndexReturnsEmptyPayloadWhenKeywordIsBlank(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['q', null, '   '],
            ['limit', null, 10],
        ]);

        $searchService = $this->createMock(SearchService::class);
        $searchService->expects($this->never())->method('getSearchSuggestions');

        $controller = $this->getMockBuilder(Suggest::class)
            ->setConstructorArgs([$searchService])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => true,
                'suggestions' => [],
                'data' => [],
            ])
            ->willReturn('json');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsSearchSuggestions(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['q', null, 'bag'],
            ['limit', null, '5'],
        ]);

        $searchService = $this->createMock(SearchService::class);
        $searchService->expects($this->once())
            ->method('getSearchSuggestions')
            ->with('bag', 5)
            ->willReturn([
                ['text' => 'Travel Bag'],
            ]);

        $controller = $this->getMockBuilder(Suggest::class)
            ->setConstructorArgs([$searchService])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => true,
                'suggestions' => [['text' => 'Travel Bag']],
                'data' => [['text' => 'Travel Bag']],
            ])
            ->willReturn('json');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
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
}
