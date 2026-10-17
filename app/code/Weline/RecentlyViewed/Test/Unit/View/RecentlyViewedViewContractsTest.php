<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllRecentlyViewedViewContractsPass().
 */
final class RecentlyViewedViewContractsTest extends TestCase
{
    public function testAllRecentlyViewedViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'RecentlyViewedWidget::testRegistrationPinsDefaultInjectionSlot' => function () {
            $this->assert_RecentlyViewedWidget_testRegistrationPinsDefaultInjectionSlot();
        },
            'RecentlyViewedWidget::testEmptyPathEmitsHiddenNonEmptyShellWithTestId' => function () {
            $this->assert_RecentlyViewedWidget_testEmptyPathEmitsHiddenNonEmptyShellWithTestId();
        },
            'RecentlyViewedWidget::testHydrateJsFormatsPriceWithCurrencyGlyph' => function () {
            $this->assert_RecentlyViewedWidget_testHydrateJsFormatsPriceWithCurrencyGlyph();
        },
            'RecentlyViewedWidget::testSectionBackgroundIsTransparent' => function () {
            $this->assert_RecentlyViewedWidget_testSectionBackgroundIsTransparent();
        },
        ];
    }

private function assert_RecentlyViewedWidget_testRegistrationPinsDefaultInjectionSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/RecentlyViewed' . '/extends/module/Weline_Widget/Weline_RecentlyViewed/widget.php';
    $tpl = 'Weline_RecentlyViewed::templates/frontend/widgets/recently-viewed.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/RecentlyViewed' . '/view/templates/frontend/widgets/recently-viewed.phtml');
    self::assertStringContainsString('@widget.code {recently-viewed}', $src);
    self::assertStringContainsString('@widget.slot {product-recently-viewed}', $src);
    self::assertStringContainsString('"slot":"product-recently-viewed"', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_RecentlyViewedWidget_testEmptyPathEmitsHiddenNonEmptyShellWithTestId(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/RecentlyViewed' . '/view/templates/frontend/widgets/recently-viewed.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('@widget.default_injections', $source);
    self::assertStringContainsString('product-recently-viewed', $source);
    self::assertStringContainsString('data-testid="recently-viewed-empty"', $source);
    self::assertStringContainsString('hidden', $source);
    self::assertStringContainsString('aria-hidden="true"', $source);
    self::assertStringNotContainsString('暂无浏览记录', $source);
    self::assertStringContainsString('Never return a blank string', $source);
    self::assertStringContainsString('Keep visually hidden', $source);
    self::assertStringContainsString('RecentlyViewedService', $source);
    self::assertStringContainsString('<w:product:card ids="products"', $source);
    self::assertStringNotContainsString('ProductCardRenderer::projectFromProducts', $source);
    self::assertStringContainsString('data-weline-load="recentlyViewed"', $source);
    self::assertStringContainsString('StorefrontPdpShelfDeferral::shouldDeferCardAssembly', $source);
    self::assertStringContainsString('data-testid="recently-viewed-deferred"', $source);
    self::assertStringContainsString('data-hydrate-operation="recentlyViewedCards"', $source);
    self::assertStringContainsString('data-weline-hydrate="1"', $source);
    self::assertStringContainsString('data-wrv-cookie-name=', $source);
    self::assertStringContainsString('data-wrv-cookie-path=', $source);
    self::assertStringContainsString('CookieScope::qualifyName', $source);
    self::assertStringContainsString('"limit":6', $source);
    self::assertStringContainsString('@param limit {default=6', $source);
    self::assertStringContainsString('data-wrv-track', $source);
    self::assertStringContainsString('wrv-stage', $source);
    self::assertStringContainsString('density="standard"', $source);
    self::assertStringContainsString("\$cardClass = 'wpc-listing-card wrv-card'", $source);
    self::assertStringContainsString('class="cardClass"', $source);
    self::assertStringContainsString('show-sku="true"', $source);
    self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $source);
    self::assertStringNotContainsString("'density' => 'shelf'", $source);
    self::assertStringNotContainsString('ProductCardRenderer::render', $source);
    self::assertStringContainsString("\$product['slug']", $source);
    self::assertStringContainsString('$hasCurrentPrefix', $source);
    self::assertStringContainsString("product/' . \$slug", $source);
    self::assertStringContainsString('wrv-nav--prev', $source);
    self::assertDoesNotMatchRegularExpression('/if\s*\(\s*(?:empty\(\s*\$products\s*\)|\$products\s*===\s*\[\])\s*\)\s*\{\s*return\s*;\s*\}/s', $source, 'Empty product list must render a hidden shell, not bare return.');
}

private function assert_RecentlyViewedWidget_testHydrateJsFormatsPriceWithCurrencyGlyph(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/RecentlyViewed' . '/view/statics/js/widgets/recently-viewed.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('function formatCardPrice(card)', $js);
    self::assertStringContainsString('formatted_price', $js);
    self::assertStringContainsString('Never invent a client currency symbol table', $js);
    self::assertStringContainsString('function resolveCookieScope', $js);
    self::assertStringContainsString('data-wrv-cookie-name', $js);
    self::assertStringContainsString('data-website-id', $js);
    self::assertStringNotContainsString("pairs[i].name.indexOf(COOKIE_BASE + '_') === 0", $js, 'Client must not pick the first sibling website recently-viewed cookie.');
    self::assertStringNotContainsString("esc(price.toFixed(2))", $js, 'Hydrate cards must not render bare amounts without a currency glyph.');
    self::assertDoesNotMatchRegularExpression("/code === 'USD'|symbol = '\\\$'|symbol = '¥'/", $js, 'Hydrate JS must not keep a local currency glyph map.');
}

private function assert_RecentlyViewedWidget_testSectionBackgroundIsTransparent(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/RecentlyViewed' . '/view/statics/css/widgets/recently-viewed.css';
    self::assertFileExists($path);
    $css = (string) file_get_contents($path);
    self::assertMatchesRegularExpression('/\.weline-recently-viewed\s*\{[\s\S]*?background:\s*transparent;/', $css, 'Recently-viewed block must use transparent section background.');
    self::assertMatchesRegularExpression('/\.wrv-track\.layout-carousel\s+\.wrv-card\s*\{[\s\S]*?flex:\s*0\s+0\s+15rem;/', $css, 'Carousel width must bind .wrv-card (server + hydrate), not only .weline-product-card.');
    self::assertStringContainsString('.wrv-hydrate-media img', $css);
    self::assertStringNotContainsString('background: var(--wrv-surface);', $css, 'Section must not paint raised surface as block fill.');
}
}
