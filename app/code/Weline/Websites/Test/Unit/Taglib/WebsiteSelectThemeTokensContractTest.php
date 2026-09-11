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

    public function testWebsiteSelectSearchIncludesNameAndDomain(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/WebsiteSelect.php';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('搜索站点名称或域名', $content);
        self::assertStringContainsString('function optionSearchHaystack(item){', $content);
        self::assertStringContainsString('item.url, item.domain, item.code', $content);
        self::assertStringContainsString('optionSearchHaystack(item).indexOf(kw)', $content);
    }

    public function testWebsiteSelectChevronActionsAreVerticallyCentered(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/WebsiteSelect.php';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('.weline-website-actions{display:inline-flex;align-items:center;justify-content:center', $content);
        self::assertStringContainsString('align-self:center', $content);
        self::assertStringContainsString('.weline-website-chevron{display:inline-flex;align-items:center;justify-content:center', $content);
        self::assertStringContainsString('weline-website-chevron" aria-hidden="true">▾</span>', $content);
        self::assertStringContainsString('min-height:var(--weline-control-height);padding:0 var(--weline-space-3)', $content);
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-website-trigger\{[^}]*[^n-]height:var\(--weline-control-height\)/',
            $content,
        );
    }
}
