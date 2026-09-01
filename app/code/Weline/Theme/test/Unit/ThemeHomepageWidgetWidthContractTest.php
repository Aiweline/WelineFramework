<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeHomepageWidgetWidthContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function homepageContentWidgets(): iterable
    {
        yield 'featured-products' => ['view/theme/frontend/widgets/product/featured-products/default.phtml'];
        yield 'category-grid' => ['view/theme/frontend/widgets/category/category-grid/default.phtml'];
        yield 'new-arrivals' => ['view/theme/frontend/widgets/product/new-arrivals/default.phtml'];
    }

    /**
     * Shell A: widgets render inside layout .w-container — no private max-width or horizontal gutter.
     *
     * @dataProvider homepageContentWidgets
     */
    public function testHomepageContentWidgetsDeferWidthToLayoutContainer(string $relativePath): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('width: 100%;', $content);
        $this->assertStringContainsString('max-width: none;', $content);
        $this->assertStringContainsString('padding-inline: 0;', $content);
        $this->assertStringContainsString('box-sizing: border-box;', $content);
        $this->assertStringNotContainsString('max-width: var(--layout-max-width);', $content);
        $this->assertStringNotContainsString('margin: 0 auto;', $content);
    }

    public function testHomepageLayoutProvidesContentGutterForNonHeroSections(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/homepage/default.phtml';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            '.homepage-section:not(.homepage-hero):not(.homepage-promo) > *',
            $content
        );
        $this->assertStringContainsString('var(--weline-layout-content-max-width)', $content);
        $this->assertStringContainsString('var(--weline-layout-content-padding-inline)', $content);
    }

    public function testHomepageHeroAndPromoSectionsHaveZeroBlockPadding(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/homepage/default.phtml';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('.theme-layout-homepage .homepage-hero,', $content);
        $this->assertStringContainsString('.theme-layout-homepage .homepage-promo,', $content);
        $this->assertStringContainsString(
            '.theme-layout-homepage .homepage-section:has([data-widget-code="hero-slider"]),',
            $content
        );
        $this->assertStringContainsString(
            '.theme-layout-homepage .homepage-section:has([data-widget-code="promo-banner"])',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/\.homepage-section:has\(\[data-widget-code="promo-banner"\]\)\s*\{\s*padding-block:\s*0\s*;/s',
            $content
        );
    }

    public function testHeroSliderKeepsSelfContainedFullHeight(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/banner/hero-slider/default.phtml';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('$height = trim((string)($this->getData(\'height\') ?? \'500px\'));', $content);
        $this->assertDoesNotMatchRegularExpression('/\$height\s*=\s*[^;]*120px/', $content);
        $this->assertStringNotContainsString('$compactPreview', $content);
        $this->assertStringNotContainsString('.widget-preview-canvas', $content);
        $this->assertStringNotContainsString('is-preview', $content);
        $this->assertStringNotContainsString('$height = $isPreviewMode ? \'120px\'', $content);
        $this->assertStringNotContainsString('$height = $compactPreview ? \'120px\'', $content);
    }
}
