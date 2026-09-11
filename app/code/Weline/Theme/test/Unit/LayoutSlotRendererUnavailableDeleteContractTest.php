<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 失效部件移除必须走 remove-widget(node_uid) + typed editor_context，且不得与孤儿面板混列表。
 */
final class LayoutSlotRendererUnavailableDeleteContractTest extends TestCase
{
    public function testUnavailablePanelUsesRemoveWidgetNodeUidAndSeparateCopy(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('injectUnavailableWarnings(', $source);
        self::assertStringContainsString('hasUnavailableWidgets()', $source);
        self::assertStringContainsString('getUnavailableWidgets()', $source);
        self::assertStringContainsString('data-editor-interactive', $source);
        self::assertStringContainsString('syncUnavailableWidgetsFromHtml(', $source);
        self::assertStringContainsString('z-index: 2147483000', $source);
        self::assertStringContainsString("id=\"unavailable-widgets-warning\"", $source);
        self::assertStringContainsString('失效部件', $source);
        self::assertStringContainsString('data-unavailable-node-uids=', $source);
        self::assertStringContainsString("getBackendUrl('theme/backend/theme-editor/remove-widget')", $source);
        self::assertStringContainsString('node_uid: nodeUid', $source);
        self::assertStringContainsString('payload.editor_context = editorContext', $source);
        self::assertStringContainsString("data-action=\"remove-unavailable-widget\"", $source);
        self::assertStringContainsString('resolveOrphanDeleteEditorContext(', $source);
        self::assertStringContainsString('shouldShowEditorSlotDiagnostics()', $source);
        self::assertStringContainsString('widget-unavailable-tip', $source);
        self::assertStringContainsString("type: 'layout-draft-mutated'", $source);
        self::assertStringContainsString("reason: 'remove-unavailable-widget'", $source);
        self::assertStringNotContainsString('remove-orphan-widgets', $this->unavailableMethodBody($source));
    }

    private function unavailableMethodBody(string $source): string
    {
        $start = strpos($source, 'function injectUnavailableWarnings');
        self::assertNotFalse($start);
        $end = strpos($source, 'function resolveOrphanDeleteEditorContext', $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
