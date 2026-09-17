<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Media/text locale overlays must read/write RESOURCE_I18N drafts — not layout.nodes.
 */
final class ThemeEditorMediaI18nDraftOverlayContractTest extends TestCase
{
    public function testComposeScopedNodeConfigLoadsI18nDraftOverlay(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );
        self::assertStringContainsString('function loadScopedNodeI18nDraftOverlay(', $source);
        self::assertStringContainsString('function persistScopedNodeI18nOverlay(', $source);
        self::assertStringContainsString('function readConfigPathValue(', $source);
        self::assertStringContainsString('RESOURCE_I18N', $source);
        self::assertStringContainsString(
            'Locale overlays live in RESOURCE_I18N drafts, not layout.nodes.',
            $source
        );
        self::assertStringNotContainsString(
            "\$draftConfig = \$draftPayload['translations'][\$nodeUid] ?? null;\n"
            . "            if (\\is_array(\$draftConfig)) {\n"
            . "                \$config = \$this->materializeWidgetConfigPaths(\$draftConfig, \$config);\n"
            . "            }\n"
            . "        }\n\n"
            . "        return \$this->materializeWidgetConfigPaths(\$config, []);",
            $source
        );
    }

    public function testEditorAllowsZeroRevisionI18nWorkspace(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        self::assertStringContainsString('revision 0 = empty workspace identity', $js);
        self::assertMatchesRegularExpression(
            '/function assertCurrentThemeDraftScope\([\s\S]*?'
            . 'Number\(workspace\.revision \|\| 0\) < 0[\s\S]*?'
            . '主题作用域不一致/',
            $js
        );
        self::assertDoesNotMatchRegularExpression(
            '/function assertCurrentThemeDraftScope\([\s\S]*?'
            . 'Number\(workspace\.revision \|\| 0\) <= 0[\s\S]*?'
            . '主题作用域不一致/',
            $js
        );
    }
}
