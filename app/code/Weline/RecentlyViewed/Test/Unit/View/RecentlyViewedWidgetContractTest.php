<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class RecentlyViewedWidgetContractTest extends TestCase
{
    public function testRegistrationPinsDefaultInjectionSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_RecentlyViewed/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        $widget = $widgets['recently-viewed'] ?? [];
        self::assertSame('recently-viewed', $widget['code'] ?? null);
        self::assertSame('Weline_RecentlyViewed::templates/frontend/widgets/recently-viewed.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-recently-viewed', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertSame(6, (int)(($injection['config']['limit'] ?? 0)));
        self::assertSame(6, (int)(($widget['params']['limit']['default'] ?? 0)));
    }

    public function testEmptyPathEmitsHiddenNonEmptyShellWithTestId(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/recently-viewed.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.default_injections', $source);
        self::assertStringContainsString('product-recently-viewed', $source);
        self::assertStringContainsString('data-testid="recently-viewed-empty"', $source);
        self::assertStringContainsString('hidden', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);
        self::assertStringNotContainsString('暂无浏览记录', $source);
        self::assertStringContainsString('Never return a blank string', $source);
        self::assertStringContainsString('Keep visually hidden', $source);
        self::assertStringContainsString('RecentlyViewedService', $source);
        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('data-weline-load="recentlyViewed"', $source);
        self::assertStringContainsString('StorefrontPdpShelfDeferral::shouldDeferCardAssembly', $source);
        self::assertStringContainsString('data-testid="recently-viewed-deferred"', $source);
        self::assertStringContainsString('data-hydrate-operation="recentlyViewedCards"', $source);
        self::assertStringContainsString('data-weline-hydrate="1"', $source);
        self::assertStringContainsString('"limit":6', $source);
        self::assertStringContainsString('@param limit {default=6', $source);
        self::assertStringContainsString('data-wrv-track', $source);
        self::assertStringContainsString('wrv-stage', $source);
        self::assertStringContainsString('density="standard"', $source);
        self::assertStringContainsString('class="wpc-listing-card wrv-card"', $source);
        self::assertStringContainsString('show-sku="true"', $source);
        self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $source);
        self::assertStringNotContainsString('density="shelf"', $source);
        self::assertStringNotContainsString('ProductCardRenderer::render', $source);
        self::assertStringContainsString("\$product['slug']", $source);
        self::assertStringContainsString('$hasCurrentPrefix', $source);
        self::assertStringContainsString("product/' . \$slug", $source);
        self::assertStringContainsString('wrv-nav--prev', $source);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*(?:empty\(\s*\$products\s*\)|\$products\s*===\s*\[\])\s*\)\s*\{\s*return\s*;\s*\}/s',
            $source,
            'Empty product list must render a hidden shell, not bare return.',
        );
    }

    public function testSectionBackgroundIsTransparent(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/widgets/recently-viewed.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);
        self::assertMatchesRegularExpression(
            '/\.weline-recently-viewed\s*\{[\s\S]*?background:\s*transparent;/',
            $css,
            'Recently-viewed block must use transparent section background.',
        );
        self::assertStringNotContainsString(
            'background: var(--wrv-surface);',
            $css,
            'Section must not paint raised surface as block fill.',
        );
    }
}
