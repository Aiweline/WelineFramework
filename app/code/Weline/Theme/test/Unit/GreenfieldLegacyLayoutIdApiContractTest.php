<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Greenfield closeout: numeric layout_id ThemeLayoutService APIs must be fail-closed stubs.
 */
final class GreenfieldLegacyLayoutIdApiContractTest extends TestCase
{
    public function testLegacyNumericLayoutIdApisAreFailClosedStubs(): void
    {
        $servicePath = dirname(__DIR__, 2) . '/Service/ThemeLayoutService.php';
        $legacyJs = dirname(__DIR__, 2) . '/view/statics/js/theme-editor.js';
        $uiJs = dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js';
        $editorPhp = dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php';

        $service = (string)file_get_contents($servicePath);
        $legacy = (string)file_get_contents($legacyJs);
        $ui = (string)file_get_contents($uiJs);
        $editor = (string)file_get_contents($editorPhp);

        foreach ([
            'function deleteWidget',
            'function updateWidgetConfig',
            'function getWidgetByLayoutId',
            'function updateSortOrder',
            'function moveWidget',
            'function swapWidgetOrder',
            'function getSlotWidgets',
            'function updateSlotWidgetsOrder',
            'function cleanOrphanWidgets',
            'function clearTemplateDeletedTombstonesForSlot',
        ] as $fn) {
            self::assertStringContainsString($fn, $service);
            self::assertStringContainsString('@deprecated Greenfield: theme_layout dropped', $service);
        }

        // Bodies must not load/save theme_layout rows for these APIs anymore.
        self::assertDoesNotMatchRegularExpression(
            '/function deleteWidget\(int \$layoutId\): bool\s*\{[^}]*->load\(/s',
            $service
        );
        self::assertDoesNotMatchRegularExpression(
            '/function getWidgetByLayoutId\(int \$layoutId\): \?array\s*\{[^}]*->load\(/s',
            $service
        );
        self::assertStringContainsString('layout_id_api_removed', $service);
        self::assertStringContainsString("throw new \\InvalidArgumentException('layout_id_api_removed')", $service);

        // Authority source + compiled UI bundle must keep the same layout_id guards.
        foreach ([$legacy, $ui] as $js) {
            self::assertStringContainsString(
                'Only send positive layout_id for pre-drop numeric rows',
                $js
            );
            self::assertStringContainsString(
                'do not put hex node_uid into layout_id',
                $js
            );
            self::assertStringContainsString(
                'layout_id: validNodeUid(layoutId) ? 0 : layoutId',
                $js
            );
        }

        self::assertStringContainsString(
            'numeric layout_id path is dead after DROP',
            $editor
        );
        self::assertStringContainsString(
            'Use buildPreviewHtmlForWidget / node_uid',
            $editor
        );
        self::assertStringNotContainsString(
            'layoutService->getWidgetByLayoutId',
            $editor
        );

        $fixture = (string)file_get_contents(
            dirname(__DIR__, 2) . '/test/e2e/backend/theme-editor-fixture.php'
        );
        self::assertStringContainsString("\$applied = \$item && !empty(\$item['node_uid']);", $fixture);
        self::assertStringNotContainsString(
            "!empty(\$item['layout_id']) || !empty(\$item['node_uid'])",
            $fixture
        );
    }
}
