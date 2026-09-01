<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerSelectionModeContractTest extends TestCase
{
    public function testSelectSetModeAllowsExpandButOnlySetIsSelectable(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Manager.php',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/eav-manager-native.js',
        );
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/eav-manager.css',
        );

        self::assertStringNotContainsString('applySelectionModeToTree', $controller);
        self::assertStringNotContainsString("resolveSelectionModeFromRequest() === 'select-set'", $controller);
        self::assertStringContainsString('selection_mode', $js);
        self::assertStringContainsString("uiMode === 'select-set'", $js);
        self::assertStringContainsString('is-selectable', $js);
        self::assertStringContainsString('is-previewable', $js);
        self::assertStringContainsString('resolveSetTreeItem', $js);
        self::assertStringContainsString('pickSelectSetNode', $js);
        self::assertStringNotContainsString('selectSetPreview', $js);
        self::assertStringNotContainsString('is-readonly-preview', $js);
        self::assertStringContainsString("uiMode === 'select-attribute'", $js);
        self::assertStringContainsString('updateSelectConfirmState', $js);
        self::assertStringContainsString('data-w-eav-tree-toolbar', $tpl);
        self::assertStringContainsString('selectSetHint', $tpl);
        self::assertStringContainsString('可展开并编辑属性组、属性与选项', $tpl);
        self::assertStringContainsString('w-eav-manager--select-set', $css);
        self::assertStringContainsString('.is-selectable', $css);
        self::assertStringContainsString('.is-previewable', $css);
    }
}
