<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 孤儿部件删除必须携带 typed editor_context，否则 remove-orphan-widgets 会返回 theme_editor_typed_scope_required。
 */
final class LayoutSlotRendererOrphanDeleteContractTest extends TestCase
{
    public function testOrphanDeletePayloadIncludesTypedEditorContext(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('resolveOrphanDeleteEditorContext(', $source);
        self::assertStringContainsString('decodeEditorContextValue(', $source);
        self::assertStringContainsString("previewContext['editor_context']", $source);
        self::assertStringContainsString('ThemeTargetIdentityResolver', $source);
        self::assertStringContainsString('theme_layout_target_type', $source);
        self::assertStringNotContainsString("getParam('target_type')", $source);
        self::assertStringContainsString('data-orphan-layout-ids=', $source);
        self::assertStringContainsString('layout_ids: orphanLayoutIds', $source);
        self::assertStringContainsString('payload.editor_context = editorContext', $source);
        self::assertStringContainsString("credentials: 'same-origin'", $source);
        self::assertStringContainsString('theme_editor_typed_scope_required', $source, 'delete flow should document typed scope requirement');
        self::assertStringContainsString('shouldShowEditorSlotDiagnostics()', $source);
        self::assertStringContainsString('previewRequestInspector->isEditorMode()', $source);
        self::assertStringContainsString('previewRequestInspector->isPreviewShellPath()', $source);
    }
}
