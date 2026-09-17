<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Widget config group accordion must have a single click owner.
 * Double-binding (initGroupToggles click + document delegation) cancels toggles.
 */
final class WidgetParamGroupToggleSingleOwnerContractTest extends TestCase
{
    public function testParamInitDoesNotBindTitleClick(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor-widget-param.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function initGroupToggles', $source);
        self::assertStringContainsString('inThemeEditorShell', $source);
        self::assertStringContainsString("title.closest('#themeEditor, #widgetConfigModal')", $source);
        self::assertStringContainsString("title.addEventListener('click'", $source);
        self::assertStringContainsString('if (inThemeEditorShell) return;', $source);
    }

    public function testThemeEditorOwnsGroupToggleDelegation(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("e.target.closest('.w-param-group-title, [data-w-param-group-toggle]')", $source);
        self::assertStringContainsString('setParamGroupExpanded(group, !currentlyExpanded)', $source);
        self::assertStringContainsString('唯一点击入口', $source);
        self::assertStringContainsString('分组折叠改由下方手风琴委托统一处理', $source);
        self::assertSame(1, substr_count($source, "closest('[data-config-group-toggle]')") + substr_count($source, "closest('.config-group-title, [data-config-group-toggle]')"));
    }

    public function testWidgetSourceStaysInSyncWithoutTitleClick(): void
    {
        $path = dirname(__DIR__, 4) . '/Widget/view/statics/js/widget-param-types.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('inThemeEditorShell', $source);
        self::assertStringContainsString('if (inThemeEditorShell) return;', $source);
    }
}
