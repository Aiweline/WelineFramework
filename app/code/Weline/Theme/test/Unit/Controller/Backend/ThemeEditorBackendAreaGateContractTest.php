<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Themes without a backend/ skin must not expose the 后端 editor-area option.
 * backend_theme_id>0 must never be treated as "has backend".
 */
final class ThemeEditorBackendAreaGateContractTest extends TestCase
{
    public function testEditorShellGatesBackendOptionOnThemeDirOnly(): void
    {
        $path = dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('private function themeHasBackendDir', $source);
        self::assertStringContainsString(
            "\$editorAreaOptionsHtml = \$this->editorMarkupRenderer->renderEditorAreaOptions(\n"
            . "            \$frontendHasBackend,\n"
            . "            \$editorArea\n"
            . "        );",
            $source
        );
        self::assertStringContainsString("\$this->assign('theme_has_backend', \$frontendHasBackend);", $source);
        self::assertStringNotContainsString(
            '$frontendHasBackend || $backendThemeId > 0',
            $source,
            'backend_theme_id>0 must not unlock the backend editor-area option'
        );
        self::assertStringContainsString(
            "if (!\$frontendHasBackend) {\n"
            . "            \$editorArea = PreviewContextService::AREA_FRONTEND;\n"
            . "        }",
            $source
        );
        self::assertStringContainsString(
            'Backend shell must not keep a storefront leftover page_type',
            $source
        );
        self::assertStringContainsString(
            '$pageType = ThemeLayout::PAGE_TYPE_DASHBOARD;',
            $source
        );
        // Coerce must precede the prepareAssetEditorInput call in index().
        self::assertMatchesRegularExpression(
            '/\$frontendHasBackend = \$this->themeHasBackendDir\(\$editingTheme\);\s*'
            . 'if \(\!\$frontendHasBackend\) \{\s*'
            . '\$editorArea = PreviewContextService::AREA_FRONTEND;\s*'
            . '\}[\s\S]*?'
            . 'if \(\$scopeContext instanceof ScopeContext\) \{\s*'
            . '\$assetInput = \$this->prepareAssetEditorInput/s',
            $source,
            'backend coerce must run before prepareAssetEditorInput'
        );
    }

    public function testAreaSwitchJsKeepsThemeIdAndForcesDashboard(): void
    {
        $paths = [
            dirname(__DIR__, 4) . '/view/statics/ui/pages/weline-theme-editor.js',
            dirname(__DIR__, 4) . '/view/statics/js/theme-editor.js',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('function buildCanvasBackendPreviewUrl', $source);
            self::assertStringContainsString("page_type: area === 'backend' ? 'dashboard' : null", $source);
            self::assertStringContainsString('keepThemeId', $source);
            self::assertStringNotContainsString(
                "theme_id: null,\n                    frontend_theme_id: null,\n                    backend_theme_id: null,\n                    // Do not force dashboard page_type",
                $source,
                'area switch must not clear theme_id or skip dashboard page_type'
            );
        }
    }

    public function testMarkupRendererOnlyEmitsBackendOptionWhenAsked(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/Ui/ThemeEditorMarkupRenderer.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function renderEditorAreaOptions(bool $hasBackend, string $selectedArea)', $source);
        self::assertStringContainsString('if ($hasBackend)', $source);
        self::assertStringContainsString('value="backend"', $source);
        // Option markup is gated — never emit backend without $hasBackend.
        $fnStart = strpos($source, 'function renderEditorAreaOptions');
        self::assertNotFalse($fnStart);
        $fnBody = substr($source, $fnStart, 600);
        self::assertStringContainsString('if ($hasBackend)', $fnBody);
        self::assertMatchesRegularExpression(
            '/if \(\$hasBackend\) \{[^}]*value="backend"/s',
            $fnBody
        );
    }
}
