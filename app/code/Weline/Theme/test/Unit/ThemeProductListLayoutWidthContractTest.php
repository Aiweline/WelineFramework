<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeProductListLayoutWidthContractTest extends TestCase
{
    public function testProductListRecommendationsSlotUsesSharedContentWidthToken(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/product_list/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('product-list-layout__recommendations', $content);
        $this->assertStringContainsString('grid-column: 2 / -1', $content);
        $this->assertStringContainsString('product-list-layout__sidebar', $content);
        $this->assertStringContainsString('box-sizing: border-box;', $content);
        $this->assertStringNotContainsString('<w:widget type="product" name="featured-products"', $content);
        $this->assertStringContainsString('recommended-products', $content);
    }
}
