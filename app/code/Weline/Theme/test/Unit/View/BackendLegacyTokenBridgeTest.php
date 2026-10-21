<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 后台旧组件仍使用通用 --color-* token；后台色盘只提供 bg-primary/secondary/canvas，
 * 其余必须由 foundation 的兼容桥映射到 --weline-theme-*，否则弹层会透明/文字不可读。
 */
final class BackendLegacyTokenBridgeTest extends TestCase
{
    private const FILES = [
        '/app/code/Weline/Theme/view/statics/ui/weline-foundation.css',
        '/app/code/Weline/Theme/view/ui/css/foundation.css',
    ];

    public function testBackendLegacyColorBridgeIsPresentInBothFoundationCopies(): void
    {
        foreach (self::FILES as $relative) {
            $path = BP . ltrim($relative, '/');
            self::assertFileExists($path, $relative);
            $css = (string)file_get_contents($path);
            self::assertStringContainsString('Legacy backend components still use generic --color-* names', $css, $relative);
            self::assertStringContainsString('--color-bg-surface: var(--weline-theme-surface-raised);', $css, $relative);
            self::assertStringContainsString('--color-text: var(--weline-theme-text);', $css, $relative);
            self::assertStringContainsString('--color-border: var(--weline-theme-border);', $css, $relative);
            self::assertStringContainsString('--color-overlay-50: color-mix(in srgb, var(--weline-theme-overlay) 70%, transparent);', $css, $relative);
            self::assertStringContainsString('--color-danger: var(--backend-color-danger, #ff8e9c);', $css, $relative);
            self::assertStringNotContainsString('--color-danger: var(--weline-theme-danger);', $css, $relative);
            self::assertStringContainsString('--border-width-thin: 1px;', $css, $relative);
            self::assertStringContainsString('--spacing-4: var(--weline-space-4, 1rem);', $css, $relative);
            self::assertStringContainsString('--radius-lg: var(--weline-radius-lg, .75rem);', $css, $relative);
            self::assertStringContainsString('--weline-shadow-md: var(--weline-theme-shadow-md);', $css, $relative);
        }
    }
}
