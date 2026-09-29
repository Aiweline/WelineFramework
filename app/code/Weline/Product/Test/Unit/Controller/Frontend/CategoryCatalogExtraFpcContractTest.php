<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * 分类/目录店面必须声明 @Extra type=fpc，否则无法进源站 FPC 策略与 CDN-Cache-Control 边缘链。
 */
final class CategoryCatalogExtraFpcContractTest extends TestCase
{
    public function testCategoryDeclaresNestedPathFpc(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Controller/Frontend/Category.php');
        self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src);
        self::assertStringContainsString('enabled=true', $src);
        self::assertStringContainsString('ttl=600', $src);
        self::assertStringContainsString('website/default/catalog', $src);
        // 多级分类 path（/category/sets、/category/a/b）须用 **（* 仅单段）
        self::assertStringContainsString('/category/**', $src);
    }

    public function testCatalogDeclaresListingFpc(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 4) . '/Controller/Frontend/Catalog.php');
        self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src);
        self::assertStringContainsString('website/default/catalog', $src);
        self::assertStringContainsString('/products', $src);
        self::assertStringContainsString('/category', $src);
        self::assertStringContainsString('/categories', $src);
    }
}
