<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend\Config;

use PHPUnit\Framework\TestCase;

/**
 * Old config/visual-editor shell is retired; Config entry points redirect to ThemeEditor.
 */
final class VisualEditorShellRetiredContractTest extends TestCase
{
    public function testVisualEditorAssetsAreDeleted(): void
    {
        $root = \dirname(__DIR__, 5);
        self::assertFileDoesNotExist($root . '/view/statics/js/visual-editor.js');
        self::assertFileDoesNotExist($root . '/view/statics/js/visual-editor-theme-mode.js');
        self::assertFileDoesNotExist($root . '/view/statics/css/visual-editor.css');
        self::assertFileDoesNotExist($root . '/view/templates/backend/config/visual-editor.phtml');
    }

    public function testConfigEntryPointsRedirectToThemeEditor(): void
    {
        $root = \dirname(__DIR__, 5);
        $layout = (string)\file_get_contents($root . '/Controller/Backend/Config/Layout.php');
        $themeConfig = (string)\file_get_contents($root . '/Controller/Backend/Config/ThemeConfig.php');

        self::assertStringContainsString("getBackendUrl('theme/backend/theme-editor'", $layout);
        self::assertStringNotContainsString('visual-editor.phtml', $layout);
        self::assertStringContainsString("getBackendUrl('theme/backend/theme-editor'", $themeConfig);
        self::assertStringNotContainsString('visual-editor.phtml', $themeConfig);
    }

    public function testThemeEditorJsDroppedDeadApiWiring(): void
    {
        $root = \dirname(__DIR__, 5);
        $js = (string)\file_get_contents($root . '/view/statics/js/theme-editor.js');
        foreach ([
            'apiExitPreview',
            'apiPublishAndExit',
            'apiRequestTakeover',
            'apiForceTakeover',
            'apiRenameVersion',
            'apiRenderWidget',
            'apiSaveCompiledLayout',
            'apiScopedReleaseBatch',
            'apiVirtualThemeBlockAction',
            'apiVirtualThemeSaveSource',
            'apiPreview:',
            'schedulePreviewRefresh',
            'buildWidgetCodeMetaHtml',
            'function swapWidgetOrder',
            'async function addWidget(',
            'function buildFormBody',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $js, $needle);
        }
    }
}
