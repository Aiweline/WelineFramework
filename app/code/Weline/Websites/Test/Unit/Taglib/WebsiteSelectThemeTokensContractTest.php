<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class WebsiteSelectThemeTokensContractTest extends TestCase
{
    public function testWebsiteSelectStylesPreferThemeTokensOverHardcodedLightPalette(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/WebsiteSelect.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('--weline-theme-surface', $content);
        self::assertStringContainsString('--weline-theme-surface-raised', $content);
        self::assertStringContainsString('--weline-theme-surface-muted', $content);
        self::assertStringContainsString('--weline-theme-surface-hover', $content);
        self::assertStringContainsString('--weline-theme-text', $content);
        self::assertStringContainsString('--weline-theme-border', $content);
        self::assertStringContainsString('--weline-theme-primary', $content);
        self::assertStringContainsString(
            'background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff))',
            $content
        );
        self::assertStringContainsString(
            'background:var(--weline-theme-surface-raised,var(--weline-theme-surface,var(--backend-color-card-bg,#fff)))',
            $content
        );
        self::assertStringNotContainsString('background:#f8fafc', $content);
        self::assertStringNotContainsString('background:#f1f5f9', $content);
        self::assertStringNotContainsString('color:#162033', $content);
    }
}
