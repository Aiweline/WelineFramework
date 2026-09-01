<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Controller\Router;

final class RouterTest extends TestCase
{
    public function testPublicBlogPathRoutesToBlogIndexController(): void
    {
        $path = 'blog';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('blog/frontend', $path);
        self::assertSame('Weline_Blog', $rule['module'] ?? null);
    }

    public function testPublicBlogSlugPathRoutesToBlogViewController(): void
    {
        $path = 'blog/my-first-post';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('blog/frontend/view', $path);
        self::assertSame('Weline_Blog', $rule['module'] ?? null);
    }

    public function testPublicBlogCategoryPathRoutesToCategoryController(): void
    {
        $path = 'blog/category/tech';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('blog/frontend/category', $path);
        self::assertSame('Weline_Blog', $rule['module'] ?? null);
    }

    public function testReservedCategorySlugDoesNotRouteToView(): void
    {
        $path = 'blog/category';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('blog/frontend/category', $path);
        self::assertSame('Weline_Blog', $rule['module'] ?? null);
    }

    public function testUnrelatedPathsAreNotRewritten(): void
    {
        $path = 'products';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('products', $path);
        self::assertSame([], $rule);
    }

    public function testAnExistingModuleMatchAlwaysWins(): void
    {
        $path = 'blog';
        $rule = ['module' => 'Existing_Module'];

        Router::process($path, $rule);

        self::assertSame('blog', $path);
        self::assertSame(['module' => 'Existing_Module'], $rule);
    }
}
