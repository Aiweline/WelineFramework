<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountIndexThemeContractTest extends TestCase
{
    public function testAccountIndexUsesNativeThemeSurfacesWithoutHardcodedLightPalette(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Account/index.phtml';
        $css = $root . '/view/statics/css/seo-admin.css';

        self::assertFileExists($template);
        self::assertFileExists($css);

        $templateSrc = (string) file_get_contents($template);
        $cssSrc = (string) file_get_contents($css);

        self::assertStringContainsString('<css>Weline_Seo::css/seo-admin.css</css>', $templateSrc);
        self::assertStringContainsString('w-stat-tiles seo-account-stats', $templateSrc);
        self::assertStringContainsString('w-card seo-account-panel', $templateSrc);
        self::assertStringContainsString('w-empty seo-account-empty', $templateSrc);
        self::assertStringContainsString('w-badge', $templateSrc);
        self::assertStringContainsString("'icon' => 'users'", $templateSrc);
        self::assertStringContainsString('<w:icon name="user"', $templateSrc);
        self::assertStringContainsString('data-seo-account-search', $templateSrc);
        self::assertStringContainsString('data-testid="seo-account-add"', $templateSrc);
        self::assertStringContainsString('data-seo-delete-account', $templateSrc);
        self::assertStringContainsString('data-account-confirm-delete', $templateSrc);
        self::assertStringNotContainsString('class="mdi', $templateSrc);
        self::assertStringNotContainsString('seo-empty-state', $templateSrc);
        self::assertStringNotContainsString('<style>', $templateSrc);
        self::assertStringNotContainsString('background:#fff', $templateSrc);

        self::assertStringContainsString('--weline-theme-surface', $cssSrc);
        self::assertStringContainsString('--weline-theme-text', $cssSrc);
        self::assertStringContainsString('.seo-account-page', $cssSrc);
        self::assertStringContainsString('--seo-primary: var(--weline-theme-primary', $cssSrc);
        self::assertStringNotContainsString('--seo-primary: #1e40af;', $cssSrc);
    }
}
