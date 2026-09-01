<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeFeaturedProductsCatalogContractTest extends TestCase
{
    public function testFeaturedProductsPreferRealCatalogAndHideWhenEmpty(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/product/featured-products/default.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('StorefrontProductWidgetCatalog::class', $content);
        self::assertStringContainsString('if ($products === [] && $isPreviewMode)', $content);
        self::assertStringContainsString('ThemeDemoCatalog::products', $content);
        self::assertStringContainsString('if ($products === []) {', $content);
        self::assertStringContainsString('return;', $content);
        self::assertStringContainsString("ProductCardAddToCartParams::fetchDictionary(\$product)", $content);
    }
}
