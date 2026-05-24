<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Controller\Frontend\Product;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Controller\Frontend\Product\View;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class ViewTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Frontend\Controller\BaseController::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testLayoutTypeIsProduct(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new View(
            $this->createMock(StorefrontRecentlyViewedRecorder::class),
            $this->createMock(ProductViewPageDataService::class)
        );

        $this->assertSame('product', $property->getValue($controller));
    }

    public function testProductViewCacheHostNormalizesLoopbackAndPorts(): void
    {
        $controller = new View(
            $this->createMock(StorefrontRecentlyViewedRecorder::class),
            $this->createMock(ProductViewPageDataService::class)
        );
        $method = new \ReflectionMethod(View::class, 'normalizeViewPayloadCacheHost');
        $method->setAccessible(true);

        self::assertSame('', $method->invoke($controller, '127.0.0.1:9503'));
        self::assertSame('', $method->invoke($controller, 'localhost:9503'));
        self::assertSame('shop.example.test', $method->invoke($controller, 'shop.example.test:9503'));
    }

    public function testIndexRedirectsWhenProductIdIsMissing(): void
    {
        $recorder = $this->createMock(StorefrontRecentlyViewedRecorder::class);
        $recorder->expects($this->never())->method('recordProductView');

        $pageDataService = $this->createMock(ProductViewPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 0,
            'product_id' => 0,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$recorder, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/product/list');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
    }

    public function testIndexHandlesProductNotFound(): void
    {
        $recorder = $this->createMock(StorefrontRecentlyViewedRecorder::class);
        $recorder->expects($this->never())->method('recordProductView');

        $pageDataService = $this->createMock(ProductViewPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(77)
            ->willReturn([]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 77,
            'product_id' => 0,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$recorder, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/product/list');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
    }

    public function testIndexRecordsRecentlyViewedProductWhenPageDataExists(): void
    {
        $recorder = $this->createMock(StorefrontRecentlyViewedRecorder::class);
        $recorder->expects($this->once())->method('recordProductView')->with(77);

        $pageData = [
            'product' => ['product_id' => 77, 'name' => 'Camera'],
            'breadcrumbs' => [['name' => 'Home']],
            'related_products' => [],
        ];

        $pageDataService = $this->createMock(ProductViewPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(77)
            ->willReturn($pageData);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 77,
            'product_id' => 0,
        ]));

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$recorder, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(count($pageData)))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');

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
     * @param array<string,mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = null): mixed => \array_key_exists($key, $params)
            ? $params[$key]
            : $default;
    }
}
