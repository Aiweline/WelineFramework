<?php

declare(strict_types=1);

namespace WeShop\Catalog\Test\Unit\Controller\Frontend\Category;

use PHPUnit\Framework\TestCase;
use WeShop\Catalog\Controller\Frontend\Category\View;
use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Service\CategoryService;

class ViewTest extends TestCase
{
    public function testBuildBreadcrumbsBuildsTopDownPaths(): void
    {
        $view = $this->newControllerWithoutConstructor();
        $categoryService = $this->createMock(CategoryService::class);

        $categoryService->method('getCategory')->willReturnCallback(function (int $id): ?Category {
            if ($id === 10) {
                return $this->mockCategory(10, 'Men', 'men', 0);
            }
            if ($id === 20) {
                return $this->mockCategory(20, 'Shirts', 'shirts', 10);
            }
            return null;
        });

        $result = $this->invokePrivateMethod($view, 'buildBreadcrumbs', [
            $categoryService,
            ['parent_id' => 20],
        ]);

        $this->assertSame([
            ['category_id' => 10, 'name' => 'Men', 'handle' => 'men', 'path' => 'men'],
            ['category_id' => 20, 'name' => 'Shirts', 'handle' => 'shirts', 'path' => 'men/shirts'],
        ], $result);
    }

    public function testCollectBrowseFiltersExcludesReservedParamsAndEmptyValues(): void
    {
        $view = $this->newControllerWithoutConstructor();
        $filters = $this->invokePrivateMethod($view, 'collectBrowseFilters', [[
            'id' => '5',
            'handle' => 'electronics',
            'page' => '2',
            'page_size' => '24',
            'brand' => 'apple',
            'material' => '',
            'shipping' => null,
            'color' => ['red', 'blue'],
        ]]);

        $this->assertArrayNotHasKey('id', $filters);
        $this->assertArrayNotHasKey('handle', $filters);
        $this->assertArrayNotHasKey('page', $filters);
        $this->assertArrayNotHasKey('page_size', $filters);
        $this->assertSame('apple', $filters['brand']);
        $this->assertArrayNotHasKey('material', $filters);
        $this->assertArrayNotHasKey('shipping', $filters);
        $this->assertSame(['red', 'blue'], $filters['color']);
    }

    public function testFullHtmlLocalCacheCanHoldReadyGateAndTierTwoWarmupPaths(): void
    {
        $reflection = new \ReflectionClass(View::class);

        $maxItems = $reflection->getReflectionConstant('VIEW_PAYLOAD_FULL_HTML_MAX_ITEMS')?->getValue();
        $retainItems = $reflection->getReflectionConstant('VIEW_PAYLOAD_FULL_HTML_RETAIN_ITEMS')?->getValue();

        $this->assertGreaterThanOrEqual(64, $maxItems);
        $this->assertGreaterThanOrEqual(32, $retainItems);
    }

    private function newControllerWithoutConstructor(): View
    {
        $reflection = new \ReflectionClass(View::class);
        /** @var View $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(View $view, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod(View::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($view, $arguments);
    }

    private function mockCategory(int $id, string $name, string $handle, int $parentId): Category
    {
        $reflection = new \ReflectionClass(Category::class);
        /** @var Category $category */
        $category = $reflection->newInstanceWithoutConstructor();
        $category->setData(Category::schema_fields_ID, $id);
        $category->setData(Category::schema_fields_NAME, $name);
        $category->setData(Category::schema_fields_HANDLE, $handle);
        $category->setData(Category::schema_fields_PARENT_ID, $parentId);
        return $category;
    }
}
