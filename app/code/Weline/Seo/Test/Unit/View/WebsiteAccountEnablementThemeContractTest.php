<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WebsiteAccountEnablementThemeContractTest extends TestCase
{
    public function testWidgetUsesNativeThemeTokensWithoutInlineHardcodedPalette(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Widget/WebsiteAccountEnablement.phtml';
        $css = $root . '/view/statics/css/seo-admin.css';
        $hook = $root . '/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml';

        self::assertFileExists($template);
        self::assertFileExists($css);
        self::assertFileExists($hook);

        $templateSrc = (string) file_get_contents($template);
        $cssSrc = (string) file_get_contents($css);
        $hookSrc = (string) file_get_contents($hook);

        self::assertStringContainsString('<css>Weline_Seo::css/seo-admin.css</css>', $templateSrc);
        self::assertStringContainsString('w-card seo-widget-shell', $templateSrc);
        self::assertStringContainsString('w-empty seo-empty', $templateSrc);
        self::assertStringContainsString('w-badge seo-chip', $templateSrc);
        self::assertStringNotContainsString('<style>', $templateSrc);
        self::assertStringNotContainsString('ms-2', $templateSrc);
        self::assertStringNotContainsString('--seo-widget-bg: #fff', $templateSrc);

        self::assertStringContainsString('.seo-website-account-widget', $cssSrc);
        self::assertStringContainsString('--weline-theme-surface', $cssSrc);
        self::assertStringContainsString('--weline-theme-border', $cssSrc);
        self::assertStringContainsString('--weline-theme-surface-muted', $cssSrc);

        self::assertStringNotContainsString('w-disclosure__trigger collapsed', $hookSrc);
        self::assertStringContainsString('SEO 协议与搜索提交', $hookSrc);
    }
}
