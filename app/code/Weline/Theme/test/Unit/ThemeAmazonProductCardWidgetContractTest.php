<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeAmazonProductCardWidgetContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function productCardShelfWidgets(): iterable
    {
        yield 'featured-products' => ['view/theme/frontend/widgets/product/featured-products/default.phtml'];
        yield 'new-arrivals' => ['view/theme/frontend/widgets/product/new-arrivals/default.phtml'];
        yield 'deals-of-day' => ['view/theme/frontend/widgets/product/deals-of-day/default.phtml'];
        yield 'bestsellers' => ['view/theme/frontend/widgets/product/bestsellers/default.phtml'];
    }

    /**
     * @dataProvider productCardShelfWidgets
     */
    public function testHomepageProductWidgetsUseSharedProductCardTag(string $relativePath): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('weline-product-card-shelf', $content);
        $this->assertStringContainsString('<w:product:card', $content);
        $this->assertStringContainsString('density="shelf"', $content);
        $this->assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $content);
        $this->assertStringContainsString('查看更多', $content);
        // CSS 必须落在 section 内；写在 wrapper 外层会被槽位装配剥掉。
        $this->assertMatchesRegularExpression(
            '/<section\b[\s\S]*?>\s*<\?= ProductCardRenderer::emitStylesheetLinkOnce\(\) \?>/s',
            $content
        );
        $this->assertStringNotContainsString('weline-amz-card-widget', $content);
        $this->assertStringNotContainsString('amazon-product-card.css', $content);
        $this->assertStringNotContainsString('name="pin"', $content);
    }

    public function testCanonicalProductCardStylesheetDefinesPrimaryCta(): void
    {
        $path = dirname(__DIR__, 3) . '/Product/view/statics/css/frontend/product-card.css';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('.weline-product-card', $content);
        $this->assertStringContainsString('.wpc-cart-btn', $content);
        $this->assertStringContainsString('--wpc-price:', $content);
        $this->assertStringContainsString('.wpc-cta', $content);
        $this->assertStringContainsString('flex-direction: column', $content);
    }

    public function testThemeCssDoesNotDefineProductCardBodyRules(): void
    {
        $themeCss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/assets/css/theme.css'
        );
        self::assertStringNotContainsString('.weline-product-card .wpc-body', $themeCss);
    }
}
