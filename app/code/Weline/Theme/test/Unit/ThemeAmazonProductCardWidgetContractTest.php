<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeAmazonProductCardWidgetContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function amazonStyledWidgets(): iterable
    {
        yield 'featured-products' => ['view/theme/frontend/widgets/product/featured-products/default.phtml'];
        yield 'new-arrivals' => ['view/theme/frontend/widgets/product/new-arrivals/default.phtml'];
    }

    /**
     * @dataProvider amazonStyledWidgets
     */
    public function testHomepageProductWidgetsUseSharedAmazonCardSurface(string $relativePath): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('weline-amz-card-widget', $content);
        $this->assertStringContainsString('amazon-product-card.css', $content);
        $this->assertStringContainsString('shopper-actions.phtml', $content);
        $this->assertStringContainsString('add-to-cart.phtml', $content);
        $this->assertStringNotContainsString('name="pin"', $content);
    }

    public function testAmazonProductCardStylesheetDefinesYellowCta(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/css/widgets/amazon-product-card.css';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('.weline-amz-card-widget .btn-add-to-cart', $content);
        $this->assertStringContainsString('--amz-card-cta-bg', $content);
        $this->assertStringContainsString('#ffd814', $content);
        $this->assertStringContainsString('--amz-card-price: #b12704', $content);
        $this->assertStringContainsString('flex-direction: row', $content);
        $this->assertStringContainsString('border: 0 !important', $content);
        $this->assertStringContainsString('color: #c45500', $content);
        $this->assertStringContainsString('color: #e47911', $content);
    }
}
