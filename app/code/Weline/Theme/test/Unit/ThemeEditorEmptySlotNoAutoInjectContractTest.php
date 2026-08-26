<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 空 slot 不得在打开编辑器或渲染布局时按 default_injections 自动回填。
 */
final class ThemeEditorEmptySlotNoAutoInjectContractTest extends TestCase
{
    public function testThemeEditorIndexDoesNotAutoApplyMissingDefaultsOnOpen(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        self::assertStringNotContainsString(
            'applyMissingForAllPageTypes(',
            $src,
            'Opening/refreshing Theme Editor must not auto-fill empty slots from default_injections'
        );
        self::assertStringContainsString(
            '打开/刷新编辑器不得按 default_injections 自动回填空 slot',
            $src
        );
    }

    public function testSlotRendererDoesNotAutoApplyMissingDefaultsOnLayoutRead(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/SlotRendererService.php'
        );

        self::assertStringNotContainsString(
            'applyMissingForLayout(',
            $src,
            'Layout render path must not auto-fill empty slots from default_injections'
        );
        self::assertStringContainsString(
            'default_injections 不得在渲染路径回填',
            $src
        );
    }

    public function testPreviewRendererKeepsEmptyLayoutsWithoutDefaultInjection(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/ThemePreviewContentRenderer.php'
        );

        self::assertStringContainsString(
            'Empty layouts and empty slots stay empty on preview/render',
            $src
        );
        self::assertStringNotContainsString('applyMissingForLayout(', $src);
        self::assertStringNotContainsString('applyMissingForAllPageTypes(', $src);
    }

    public function testExplicitSlotInitApiIsRegistered(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );
        $query = (string)file_get_contents(
            dirname(__DIR__, 2) . '/extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );
        $service = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/WidgetDefaultInjectionService.php'
        );

        self::assertStringContainsString('function postInitSlotDefaults(', $controller);
        self::assertStringContainsString('initSlotDefaultInjections(', $service);
        self::assertStringContainsString(
            "'/theme/backend/theme-editor/init-slot-defaults'",
            $query
        );
    }
}
