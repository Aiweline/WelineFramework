<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ThemeColorModeContractTest extends TestCase
{
    public function testAdminControlsUseTheNativeThreeStateThemeContract(): void
    {
        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString("['system', 'light', 'dark'].includes(value)", $runtime);
        self::assertStringContainsString('root.dataset.themePreference = preference;', $runtime);
        self::assertStringContainsString('root.dataset.theme = theme;', $runtime);
        self::assertStringContainsString("media?.addEventListener('change', onSystemChange);", $runtime);
        self::assertStringContainsString("closest('[data-w-theme-preference]')", $runtime);
        self::assertStringNotContainsString('window.bootstrap', $runtime);
        self::assertStringNotContainsString('window.jQuery', $runtime);

        $settings = $this->read('app/code/Weline/Admin/view/templates/common/right-sidebar.phtml');
        self::assertSame(3, substr_count($settings, 'data-w-theme-preference='));
        foreach (['system', 'light', 'dark'] as $preference) {
            self::assertStringContainsString('data-w-theme-preference="' . $preference . '"', $settings);
        }
        self::assertStringContainsString('data-w-component="drawer"', $settings);
        self::assertStringNotContainsString('data-bs-', $settings);

        $topbar = $this->read('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
        self::assertStringContainsString('data-w-component="menu"', $topbar);
        self::assertStringContainsString('data-w-menu-trigger', $topbar);
        self::assertStringContainsString('data-w-menu-panel', $topbar);
        self::assertSame(3, substr_count($topbar, 'data-w-theme-preference='));
        self::assertStringNotContainsString('data-bs-', $topbar);
        self::assertStringNotContainsString('dropdown-toggle', $topbar);
    }

    public function testBackendShellHasOneWelineUiAssetOwner(): void
    {
        $adminHead = $this->read('app/code/Weline/Admin/view/templates/common/head.phtml');
        $backendHeader = $this->read('app/code/Weline/Backend/view/blocks/header/base.phtml');
        $themeHead = $this->read('app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml');
        $sources = $adminHead . "\n" . $backendHeader . "\n" . $themeHead;

        self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-foundation.css'));
        self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-backend.css'));
        self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-ui.js'));
        self::assertStringContainsString('type="module"', $themeHead);

        foreach (['jquery', 'bootstrap', 'backend-components.js', 'assets/js/theme.js', 'data-bs-'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($sources));
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
