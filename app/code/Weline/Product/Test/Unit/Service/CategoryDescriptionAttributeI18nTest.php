<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;

final class CategoryDescriptionAttributeI18nTest extends TestCase
{
    public function testComposeAndEnsureWiredForAttributeI18n(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ProductCategoryAttributeService.php');
        self::assertStringContainsString('function composeDefaultDescription', $source);
        self::assertStringContainsString('function ensureDescription', $source);
        self::assertStringContainsString('LocalDescription::upsertQuiet', $source);
        self::assertStringContainsString("schema_fields_DESCRIPTION => \$description", $source);

        $local = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Category/LocalDescription.php');
        self::assertStringContainsString("schema_fields_DESCRIPTION = 'description'", $local);
        self::assertStringContainsString('writeDescription', $local);
    }

    public function testComposeDefaultDescriptionValuesViaReflection(): void
    {
        $ref = new \ReflectionClass(ProductCategoryAttributeService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('composeDefaultDescription');

        self::assertSame(
            '浏览方向盘套相关商品与配件',
            $method->invoke($service, '方向盘套', 'zh_Hans_CN'),
        );
        self::assertSame(
            'Browse steering covers products and accessories',
            $method->invoke($service, 'steering covers', 'en_US'),
        );
        self::assertSame(
            'स्पोर्ट्सवियर उत्पाद और सहायक वस्तुएँ देखें',
            $method->invoke($service, 'स्पोर्ट्सवियर', 'hi_IN'),
        );
        self::assertSame(
            'Explora productos y accesorios de Ropa deportiva',
            $method->invoke($service, 'Ropa deportiva', 'es_ES'),
        );
        self::assertSame(
            'تصفح منتجات وإكسسوارات ملابس رياضية',
            $method->invoke($service, 'ملابس رياضية', 'ar_SA'),
        );
    }

    public function testAllMenuTreeCacheKeyBumpedForAttributeEnsure(): void
    {
        self::assertSame(
            'product.all_menu_category_tree.v8.3.en_US',
            StorefrontAllMenuCategoryTreeService::logicalCacheKey(3, 'en_US'),
        );
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontAllMenuCategoryTreeService.php',
        );
        self::assertStringContainsString('ensureDescription', $source);
        self::assertStringContainsString('ProductCategoryAttributeService', $source);
    }
}
