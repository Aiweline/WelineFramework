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
        self::assertSame(24, (int)(($injection['config']['limit'] ?? 0)));
        self::assertSame(24, (int)(($widget['params']['limit']['default'] ?? 0)));
    }

    public function testEmptyPathEmitsNonEmptyShellWithTestId(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/recently-viewed.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.default_injections', $source);
        self::assertStringContainsString('product-recently-viewed', $source);
        self::assertStringContainsString('data-testid="recently-viewed-empty"', $source);
        self::assertStringContainsString('暂无浏览记录', $source);
        self::assertStringContainsString('Never return a blank string', $source);
        self::assertStringContainsString('RecentlyViewedService', $source);
        self::assertStringContainsString('data-weline-load="recentlyViewed"', $source);
        self::assertStringContainsString('"limit":24', $source);
        self::assertStringContainsString('@param limit {default=24', $source);
        self::assertStringContainsString('data-wrv-track', $source);
        self::assertStringContainsString('wrv-stage', $source);
        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('density="shelf"', $source);
        self::assertStringContainsString('class="wrv-card"', $source);
        self::assertStringNotContainsString('ProductCardRenderer::render', $source);
        self::assertStringContainsString('wrv-nav--prev', $source);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*(?:empty\(\s*\$products\s*\)|\$products\s*===\s*\[\])\s*\)\s*\{\s*return\s*;\s*\}/s',
            $source,
            'Empty product list must render a shell, not bare return.',
        );
    }
}
