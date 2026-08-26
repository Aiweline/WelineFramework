<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Product\Controller\Router;

final class RouterTest extends TestCase
{
    /**
     * @dataProvider publicCatalogPathProvider
     */
    public function testPublicCatalogPathRoutesToProductController(string $publicPath): void
    {
        $rule = [];

        Router::process($publicPath, $rule);

        self::assertSame('weline_product/frontend/catalog', $publicPath);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicCatalogPathProvider(): array
    {
        return [
            'products' => ['products'],
            'product list alias' => ['/product-list/'],
        ];
    }

    public function testPositiveNumericProductPathRoutesToNativeDetailController(): void
    {
        Context::enter(new Context());
        $path = '/product/17/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/detail', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
        self::assertSame(17, Context::current()->query('id'));
    }

    public function testCategoryPathRoutesToCategoryController(): void
    {
        Context::enter(new Context());
        $path = '/category/home/kitchen/dining/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/category', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
        self::assertSame('home/kitchen/dining', Context::current()->query('path'));
    }

    public function testSlugProductPathRoutesToNativeDetailController(): void
    {
        Context::enter(new Context());
        $path = '/product/ztot-z7l-yb300h-gasoline-dirt-bike/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/detail', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
        self::assertSame('ztot-z7l-yb300h-gasoline-dirt-bike', Context::current()->query('slug'));
    }

    /**
     * @dataProvider unrelatedPathProvider
     */
    public function testUnrelatedPathsAreNotRewritten(string $path): void
    {
        $originalPath = $path;
        $rule = [];

        Router::process($path, $rule);

        self::assertSame($originalPath, $path);
        self::assertSame([], $rule);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrelatedPathProvider(): array
    {
        return [
            'zero product id' => ['product/0'],
            'negative product id' => ['product/-2'],
            'nested product id' => ['product/17/extra'],
            'invalid slug chars' => ['product/Bad_Slug'],
            'catalog controller' => ['weline_product/frontend/catalog'],
            'nested product path' => ['products/example'],
        ];
    }

    public function testAnExistingModuleMatchAlwaysWins(): void
    {
        $path = 'products';
        $rule = ['module' => 'Existing_Module'];

        Router::process($path, $rule);

        self::assertSame('products', $path);
        self::assertSame(['module' => 'Existing_Module'], $rule);
    }
}
