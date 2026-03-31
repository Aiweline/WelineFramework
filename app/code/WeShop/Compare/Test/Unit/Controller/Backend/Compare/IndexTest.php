<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Controller\Backend\Compare;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Controller\Backend\Compare\Index;
use WeShop\Compare\Service\CompareAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class IndexTest extends TestCase
{
    public function testControllerExtendsBaseController(): void
    {
        $adminPageDataService = $this->createMock(CompareAdminPageDataService::class);
        $controller = new Index($adminPageDataService);

        $this->assertInstanceOf(BaseController::class, $controller);
    }

    public function testIndexReturnsString(): void
    {
        $service = $this->createMock(CompareAdminPageDataService::class);
        $service->expects($this->once())
            ->method('getPageData')
            ->with(1, 20, ['customer_id' => '', 'product_id' => ''], 0)
            ->willReturn([
                'compare_items' => [],
                'summary' => ['total' => 0],
                'filters' => ['customer_id' => '', 'product_id' => ''],
                'pagination' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'page_count' => 1,
                ],
                'editing_id' => 0,
            ]);

        $controller = $this->buildController($service, []);

        $result = $controller->index();
        $this->assertIsString($result);
        $this->assertSame('compare_index', $result);
    }

    public function testIndexWithPaginationParams(): void
    {
        $service = $this->createMock(CompareAdminPageDataService::class);
        $service->expects($this->once())
            ->method('getPageData')
            ->with(3, 50, ['customer_id' => '7', 'product_id' => ''], 0)
            ->willReturn([
                'compare_items' => [],
                'summary' => ['total' => 0],
                'filters' => ['customer_id' => '7', 'product_id' => ''],
                'pagination' => [
                    'page' => 3,
                    'page_size' => 50,
                    'total' => 0,
                    'page_count' => 1,
                ],
                'editing_id' => 0,
            ]);

        $controller = $this->buildController($service, [
            'page' => '3',
            'page_size' => '50',
            'customer_id' => '7',
        ]);

        $result = $controller->index();

        $this->assertIsString($result);
        $this->assertSame('compare_index', $result);
    }

    public function testIndexWithFilters(): void
    {
        $filters = [
            'customer_id' => '5',
            'product_id' => '101',
        ];

        $service = $this->createMock(CompareAdminPageDataService::class);
        $service->expects($this->once())
            ->method('getPageData')
            ->with(1, 20, $filters, 0)
            ->willReturn([
                'compare_items' => [],
                'summary' => ['total' => 0],
                'filters' => $filters,
                'pagination' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'page_count' => 1,
                ],
                'editing_id' => 0,
            ]);

        $controller = $this->buildController($service, [
            'customer_id' => '5',
            'product_id' => '101',
        ]);

        $result = $controller->index();

        $this->assertIsString($result);
        $this->assertSame('compare_index', $result);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function buildController(CompareAdminPageDataService $service, array $params): Index
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = '') => $params[$key] ?? $default);

        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturn('/admin/mock');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$service])
            ->onlyMethods(['fetch', 'assign'])
            ->getMock();
        $controller->method('fetch')->willReturn('compare_index');
        $controller->method('assign')->willReturnSelf();

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, '_url', $url);

        return $controller;
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
            if (!$reflection) {
                throw new \RuntimeException("Property {$property} not found.");
            }
        }

        $prop = $reflection->getProperty($property);
        $prop->setValue($target, $value);
    }
}
