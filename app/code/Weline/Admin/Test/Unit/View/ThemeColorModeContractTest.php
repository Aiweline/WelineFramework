<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ThemeColorModeContractTest extends TestCase
{
    public function testAdminControlsExposeKeyboardAccessibleThreeStateMode(): void
    {
        $appJs = $this->read('app/code/Weline/Admin/view/statics/assets/js/app.js');
        self::assertStringContainsString("mode !== 'system' && mode !== 'light' && mode !== 'dark'", $appJs);
        self::assertStringContainsString("data-theme-preference", $appJs);
        self::assertStringContainsString('bindSystemThemeMode()', $appJs);
        self::assertStringNotContainsString("media.addEventListener('change'", $appJs);
        self::assertStringNotContainsString('media.addListener(', $appJs);
        self::assertStringContainsString('[data-weline-backend-theme-mode]', $appJs);
        self::assertStringNotContainsString('$("#theme-mode-switch, #rtl-mode-switch', $appJs);
        self::assertStringContainsString("$(document).on('change', '[data-weline-backend-theme-mode]'", $appJs);

        $rightSidebar = $this->read('app/code/Weline/Admin/view/templates/common/right-sidebar.phtml');
        self::assertStringContainsString('<option value="system"', $rightSidebar);
        self::assertStringContainsString("__('跟随系统')", $rightSidebar);
        self::assertStringContainsString('id="theme-mode-switch" data-weline-backend-theme-mode data-weline-theme-mode', $rightSidebar);

        foreach (['topbar.phtml', 'top-bar.phtml'] as $fileName) {
            $topbar = $this->read('app/code/Weline/Admin/view/blocks/backend/public/' . $fileName);
            self::assertStringContainsString('data-weline-backend-theme-trigger', $topbar);
            self::assertStringContainsString('data-weline-backend-theme-mode', $topbar);
            self::assertStringContainsString('data-weline-theme-mode="system"', $topbar);
            self::assertStringContainsString('data-bs-toggle="dropdown"', $topbar);
            self::assertStringContainsString("__('主题模式')", $topbar);
            self::assertStringNotContainsString("__('Theme mode')", $topbar);
        }
    }

    public function testVisualEditorUsesNoAdditionalThemeModeRequest(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/config/visual-editor.phtml');
        self::assertStringContainsString('id="theme-mode-preference"', $template);
        self::assertStringContainsString('visual-editor-theme-mode.js', $template);

        $bridge = $this->read('app/code/Weline/Theme/view/statics/js/visual-editor-theme-mode.js');
        self::assertStringNotContainsString('fetch(', $bridge);
        self::assertStringContainsString("window.w_query('theme', 'setBackendThemeMode'", $bridge);
        self::assertStringContainsString("type: 'switchThemeColor'", $bridge);
        self::assertStringContainsString('themeColor: preference', $bridge);
        self::assertStringContainsString("preference === 'system'", $bridge);
        self::assertStringNotContainsString("media.addEventListener('change'", $bridge);
        self::assertStringNotContainsString('media.addListener(', $bridge);

        // Login/Docs own standalone first-paint fallbacks; normal Admin and
        // visual-editor documents delegate durable system changes to Theme.
        $canonicalRuntime = $this->read('app/code/Weline/Theme/view/theme/backend/assets/js/theme.js');
        self::assertSame(1, substr_count($canonicalRuntime, "media.addEventListener('change', updateSystemTheme)"));
        self::assertSame(1, substr_count($canonicalRuntime, 'media.addListener(updateSystemTheme)'));
        self::assertStringContainsString('runtime.systemListenerBound', $canonicalRuntime);
    }

    public function testBackendShellHasOneOwnerForBlockingRuntimeAssets(): void
    {
        $adminHead = $this->read('app/code/Weline/Admin/view/templates/common/head.phtml');
        $backendHeader = $this->read('app/code/Weline/Backend/view/blocks/header/base.phtml');
        $themeHead = $this->read('app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml');
        $shellSources = $adminHead . "\n" . $backendHeader . "\n" . $themeHead;

        self::assertSame(
            1,
            substr_count($shellSources, 'backend/lib/jquery/3.6.0/jquery.js'),
            'The backend header owns jQuery; the Admin head must not load it again.'
        );
        self::assertSame(
            1,
            substr_count($shellSources, 'backend/assets/js/theme.js'),
            'The Theme head partial is the sole owner of the backend theme runtime.'
        );
        self::assertSame(
            1,
            substr_count($shellSources, 'backend/assets/js/backend-components.js'),
            'The Theme head partial is the sole owner of backend components.'
        );
        foreach (['_colors.css', '_typography.css', '_spacing.css', '_borders.css', '_shadows.css'] as $asset) {
            self::assertSame(
                1,
                substr_count($shellSources, 'backend/variables/' . $asset),
                $asset . ' must not be emitted by both Admin and Theme heads.'
            );
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
