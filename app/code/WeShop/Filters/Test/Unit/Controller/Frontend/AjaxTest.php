<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Controller\Frontend\Ajax;
use WeShop\Filters\Service\FilterCountService;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use Weline\Framework\Http\Request;

class AjaxTest extends TestCase
{
    public function testFilterReturnsErrorForInvalidCategoryId(): void
    {
        $controller = $this->createController(
            $this->createRequest([
                ['category_id', 0, 0],
                ['page', 1, 1],
                ['limit', 24, 24],
            ])
        );

        $payload = json_decode($controller->runFilter(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['success']);
        $this->assertArrayHasKey('message', $payload);
    }

    public function testFilterReturnsEmptySuccessfulEnvelopeWhenCategoryHasNoProducts(): void
    {
        $controller = $this->createController(
            $this->createRequest([
                ['category_id', 12, 12],
                ['page', 1, 1],
                ['limit', 24, 24],
            ]),
            browseResult: [
                'items' => [],
                'facets' => [],
                'applied_filters' => [],
                'pagination' => ['total' => 0, 'page' => 1, 'pages' => 0],
                'clear_all_url' => '',
            ]
        );

        $payload = json_decode($controller->runFilter(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['products']);
        $this->assertSame([], $payload['data']['filters']);
        $this->assertSame(0, $payload['data']['pagination']['total']);
    }

    public function testFilterReturnsStructuredResultEnvelope(): void
    {
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->expects($this->once())
            ->method('getFilterParams')
            ->willReturn(['brand' => ['nike']]);

        $controller = $this->createController(
            $this->createRequest([
                ['category_id', 7, 7],
                ['page', 1, 1],
                ['limit', 24, 24],
            ]),
            urlService: $urlService,
            browseCategoryIds: [7, 8],
            browseResult: [
                'items' => [
                    ['product_id' => 301, 'name' => 'Nike Bag'],
                    ['product_id' => 302, 'name' => 'Nike Watch'],
                ],
                'facets' => [
                    ['code' => 'brand', 'name' => 'Brand', 'display_type' => 'list', 'options' => []],
                ],
                'applied_filters' => [
                    ['filter_code' => 'brand', 'value' => 'nike', 'label' => 'Nike'],
                ],
                'pagination' => ['total' => 2, 'page' => 1, 'pages' => 1],
                'clear_all_url' => '/catalog/category/view?id=7',
            ]
        );

        $payload = json_decode($controller->runFilter(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']['products']);
        $this->assertSame('brand', $payload['data']['filters'][0]['code']);
        $this->assertSame('Nike', $payload['data']['applied_filters'][0]['label']);
        $this->assertSame('/catalog/category/view?id=7', $payload['data']['clear_all_url']);
    }

    public function testOptionsReturnsErrorWhenParamsAreMissing(): void
    {
        $controller = $this->createController(
            $this->createRequest([
                ['category_id', 0, 0],
                ['filter_code', '', ''],
            ])
        );

        $payload = json_decode($controller->runOptions(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['success']);
    }

    public function testCountsReturnsCountEnvelope(): void
    {
        $countService = $this->createMock(FilterCountService::class);
        $countService->expects($this->once())
            ->method('getBatchCounts')
            ->with(['brand', 'size'], 9, [11, 12], ['brand' => ['nike']])
            ->willReturn(['brand' => ['nike' => 2]]);

        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->method('getFilterParams')->willReturn(['brand' => ['nike']]);

        $controller = $this->createController(
            $this->createRequest([
                ['category_id', 9, 9],
                ['filter_codes', '', 'brand,size'],
            ]),
            countService: $countService,
            urlService: $urlService,
            categoryProductIds: [11, 12]
        );

        $payload = json_decode($controller->runCounts(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame(['brand' => ['nike' => 2]], $payload['data']['counts']);
    }

    private function createController(
        Request $request,
        ?FilterService $filterService = null,
        ?FilterUrlService $urlService = null,
        ?FilterCountService $countService = null,
        array $categoryProductIds = [1001, 1002],
        array $browseCategoryIds = [],
        array $browseResult = []
    ): Ajax {
        $controller = new class(
            $filterService ?? $this->createMock(FilterService::class),
            $urlService ?? $this->createMock(FilterUrlService::class),
            $countService ?? $this->createMock(FilterCountService::class),
            $categoryProductIds,
            $browseCategoryIds,
            $browseResult
        ) extends Ajax {
            public function __construct(
                FilterService $filterService,
                FilterUrlService $urlService,
                FilterCountService $countService,
                private readonly array $categoryProductIds,
                private readonly array $browseCategoryIds,
                private readonly array $browseResult
            ) {
                parent::__construct($filterService, $urlService, $countService);
            }

            protected function getCategoryProductIds(int $categoryId): array
            {
                return $this->categoryProductIds;
            }

            protected function getBrowseCategoryIds(int $categoryId): array
            {
                return $this->browseCategoryIds !== [] ? $this->browseCategoryIds : [$categoryId];
            }

            protected function browseProducts(array $filters, int $page, int $limit, array $categoryIds): array
            {
                return $this->browseResult;
            }

            public function runFilter(): string
            {
                return parent::filter();
            }

            public function runOptions(): string
            {
                return parent::options();
            }

            public function runCounts(): string
            {
                return parent::counts();
            }
        };

        $this->setProtectedProperty($controller, 'request', $request);

        return $controller;
    }

    private function createRequest(array $map): Request
    {
        $request = $this->createMock(Request::class);
        $values = [];
        foreach ($map as $row) {
            $values[$row[0]] = $row[2];
        }

        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($values) {
                return $values[$key] ?? $default;
            }
        );

        return $request;
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
