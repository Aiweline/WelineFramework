<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * 主题编辑器自有壳：layoutType=theme-editor.default，且不挂 base::body-end（建站助手等 shell float）。
 */
final class ThemeEditorShellLayoutContractTest extends TestCase
{
    public function testThemeEditorUsesDedicatedShellLayoutWithoutBaseBodyEnd(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString("private function useThemeEditorShellLayout(): void", $controller);
        self::assertStringContainsString("\$this->layoutType = 'theme-editor.default';", $controller);
        self::assertStringContainsString('$this->useThemeEditorShellLayout();', $controller);
        self::assertStringNotContainsString("\$this->layoutType = 'fullscreen.default';", $controller);
        self::assertStringNotContainsString('useFullscreenEditorLayout', $controller);

        $layout = $this->read('app/code/Weline/Theme/view/theme/backend/layouts/theme-editor/default.phtml');
        self::assertStringContainsString('data-weline-shell="theme-editor"', $layout);
        self::assertStringContainsString('Weline_Theme::backend::layouts::theme-editor::body-end', $layout);
        self::assertStringContainsString('Weline_Admin::common/head.phtml', $layout);
        self::assertStringNotContainsString('Weline_Theme::backend::layouts::base::body-end', $layout);
        self::assertStringNotContainsString('type="topbar"', $layout);
        self::assertStringNotContainsString('type="footer"', $layout);

        $hooks = $this->read('app/code/Weline/Theme/hook.php');
        self::assertStringContainsString("'Weline_Theme::backend::layouts::theme-editor::body-end'", $hooks);
        self::assertStringContainsString('theme-editor 不引用本 hook', $hooks);
    }

    private function read(string $relative): string
    {
        $path = BP . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
