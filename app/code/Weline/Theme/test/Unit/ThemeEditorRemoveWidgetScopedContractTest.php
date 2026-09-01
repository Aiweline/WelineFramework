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
        self::assertStringContainsString('layout_node_removed', $editorJs);
    }
}
