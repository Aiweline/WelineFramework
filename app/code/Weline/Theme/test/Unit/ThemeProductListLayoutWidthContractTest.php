<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeProductListLayoutWidthContractTest extends TestCase
{
    public function testProductListRecommendationsSlotUsesSharedContentWidthToken(): void
    {
        $path = dirname(__DIR__, 3) . '/Product/view/theme/frontend/layouts/products/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('products-layout__recommendations', $content);
        $this->assertStringContainsString('grid-column: 2 / -1', $content);
        $this->assertStringContainsString('products-layout__sidebar', $content);
        $this->assertStringContainsString('box-sizing: border-box;', $content);
        $this->assertStringNotContainsString('<w:widget type="product" name="featured-products"', $content);
        $this->assertStringContainsString('recommended-products', $content);
    }
}
