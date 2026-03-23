<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Controller\Frontend\Review;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Controller\Frontend\Review\Index;
use WeShop\Review\Service\ReviewPageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testLayoutTypeIsReview(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new Index(
            $this->createMock(ReviewPageDataService::class),
            $this->createMock(\Weline\Framework\Http\Url::class)
        );

        $this->assertSame('review', $property->getValue($controller));
    }

    public function testIndexRedirectsWhenProductIdMissing(): void
    {
        $pageDataService = $this->createMock(ReviewPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $url = $this->createMock(\Weline\Framework\Http\Url::class);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, 0],
        ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageDataService, $url])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects($this->once())->method('redirect')->with('catalog/category');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');
        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsReviewPageData(): void
    {
        $pageDataService = $this->createMock(ReviewPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(88, 1, 20)
            ->willReturn([
                'reviews' => [['review_id' => 1]],
                'total' => 1,
            ]);

        $url = $this->createMock(\Weline\Framework\Http\Url::class);
        $url->expects($this->once())->method('getUrl')->with('review/create')->willReturn('/review/create');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, 88],
            ['page', null, 1],
            ['page_size', null, 20],
        ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageDataService, $url])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(5))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('page', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
