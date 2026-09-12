<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductStorefrontBreadcrumbBuilder;

final class ProductStorefrontBreadcrumbBuilderTest extends TestCase
{
    public function testBuildsMultipleTrailsAndPicksPreferredCategory(): void
    {
        $builder = (new \ReflectionClass(ProductStorefrontBreadcrumbBuilder::class))
            ->newInstanceWithoutConstructor();

        $result = $builder->build(
            1,
            113,
            ['name' => '长安忆'],
            '长安忆',
            'https://shop.test/product/changanyi',
            20,
            '',
            [
                ['category_id' => 20, 'selected' => 1, 'position' => 1],
                ['category_id' => 30, 'selected' => 1, 'position' => 0],
            ],
            [
                'by_id' => [
                    10 => ['id' => 10, 'parent_id' => 0, 'name' => '女装', 'url' => 'https://shop.test/category/women', 'path' => 'women'],
                    20 => ['id' => 20, 'parent_id' => 10, 'name' => '襦裙', 'url' => 'https://shop.test/category/women/ruqun', 'path' => 'women/ruqun'],
                    30 => ['id' => 30, 'parent_id' => 0, 'name' => '唐制', 'url' => 'https://shop.test/category/tang', 'path' => 'tang'],
                ],
            ],
        );

        self::assertCount(2, $result['trails']);
        self::assertSame(20, $result['primary_category_id']);
        self::assertSame(['首页', '女装', '襦裙', '长安忆'], array_column($result['primary'], 'name'));
        self::assertSame('https://shop.test/', $result['primary'][0]['url']);
        self::assertSame('', $builder->toVisibleItems($result['primary'])[3]['url']);
    }

    public function testSkipsDropshipShellOrganizationAncestors(): void
    {
        $builder = (new \ReflectionClass(ProductStorefrontBreadcrumbBuilder::class))
            ->newInstanceWithoutConstructor();

        $result = $builder->build(
            1,
            113,
            ['name' => '菜板'],
            '菜板',
            'https://shop.test/product/board',
            40,
            '',
            [
                ['category_id' => 40, 'selected' => 1, 'position' => 1],
            ],
            [
                'by_id' => [
                    10 => ['id' => 10, 'parent_id' => 0, 'name' => '货源商城', 'url' => 'https://shop.test/category/sourcing', 'path' => 'sourcing'],
                    20 => ['id' => 20, 'parent_id' => 10, 'name' => 'CJ货源', 'url' => 'https://shop.test/category/sourcing/cj', 'path' => 'sourcing/cj'],
                    30 => ['id' => 30, 'parent_id' => 20, 'name' => '家居', 'url' => 'https://shop.test/category/sourcing/cj/home', 'path' => 'sourcing/cj/home'],
                    40 => ['id' => 40, 'parent_id' => 30, 'name' => '收纳', 'url' => 'https://shop.test/category/sourcing/cj/home/storage', 'path' => 'sourcing/cj/home/storage'],
                ],
            ],
        );

        self::assertSame(['首页', '家居', '收纳', '菜板'], array_column($result['primary'], 'name'));
    }

    public function testRefererCategoryPathSelectsMatchingTrail(): void
    {
        $builder = (new \ReflectionClass(ProductStorefrontBreadcrumbBuilder::class))
            ->newInstanceWithoutConstructor();

        $result = $builder->build(
            1,
            113,
            [],
            '商品',
            'https://shop.test/product/x',
            0,
            'https://shop.test/category/tang',
            [
                ['category_id' => 20, 'selected' => 1, 'position' => 0],
                ['category_id' => 30, 'selected' => 1, 'position' => 1],
            ],
            [
                'by_id' => [
                    20 => ['id' => 20, 'parent_id' => 0, 'name' => '襦裙', 'url' => 'https://shop.test/category/ruqun', 'path' => 'ruqun'],
                    30 => ['id' => 30, 'parent_id' => 0, 'name' => '唐制', 'url' => 'https://shop.test/category/tang', 'path' => 'tang'],
                ],
            ],
        );

        self::assertSame(30, $result['primary_category_id']);
        self::assertSame(['首页', '唐制', '商品'], array_column($result['primary'], 'name'));
    }
}
