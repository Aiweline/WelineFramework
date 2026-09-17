<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 删除部件必须同步 scoped workspace remove_node，否则刷新后 projectDraft 会投影回已删部件。
 */
final class ThemeEditorRemoveWidgetScopedContractTest extends TestCase
{
    public function testRemoveWidgetSyncsScopedWorkspaceTombstone(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );
        $editorJs = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        $writeService = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/Scoped/ThemeScopedLayoutWriteService.php'
        );

        self::assertStringContainsString('removeScopedLayoutNodeFromWorkspace(', $controller);
        self::assertStringContainsString('resolveScopedNodeUidForRemoval(', $controller);
        self::assertStringContainsString('ThemeScopedLayoutWriteService::class', $controller);
        self::assertStringContainsString('removeWidget(', $controller);
        self::assertStringContainsString("'save_button' => false", $controller);
        self::assertStringContainsString('scope: { identity: state.scopeIdentity }', $editorJs);
        self::assertStringContainsString('editor_context: buildTypedEditorContext(\'layout\')', $editorJs);
        self::assertStringNotContainsString(
            '$recordExists ? $this->layoutService->deleteWidget($layoutId)',
            $controller,
            'Remove widget must not delete legacy theme_layout rows'
        );
        self::assertStringNotContainsString(
            '$this->layoutService->deleteWidget($layoutId)',
            $controller,
        );
        self::assertStringContainsString('node_uid: nodeUid', $editorJs);
        self::assertStringContainsString('queueRemovedLayoutNode(result', $editorJs);
        self::assertStringContainsString('widgetEl.remove();', $editorJs);
        self::assertStringContainsString('function restoreSlotContentAfterWidgetRemoval(', $editorJs);
        self::assertStringContainsString('restoreSlotContentAfterWidgetRemoval(slot, result);', $editorJs);
        self::assertSame(
            3,
            substr_count($editorJs, 'restoreSlotContentAfterWidgetRemoval(slot, result);'),
            'All three delete paths must use restoreSlotContentAfterWidgetRemoval'
        );
        self::assertStringNotContainsString(
            'w-theme-editor-slot-placeholder__title">插槽原本为空',
            $editorJs,
            'Deleting a widget must not JS-backfill a fake empty-slot error placeholder'
        );
        self::assertStringNotContainsString(
            'w-theme-editor-slot-placeholder__title">拖入部件到此插槽',
            $editorJs,
            'Deleting a widget must not JS-backfill a drag-into-empty-slot placeholder'
        );
        self::assertStringContainsString("'op' => ThemePatchCommand::OP_REMOVE_NODE", $writeService);
        self::assertStringContainsString("summary: 'layout_node_removed'", $writeService);
    }
}
