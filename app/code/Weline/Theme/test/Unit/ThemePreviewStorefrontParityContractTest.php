<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 整页 theme-preview/content 必须与店面 chrome 保真。
 */
final class ThemePreviewStorefrontParityContractTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
        self::assertFileExists($path, $relative);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testSlotRendererDoesNotTreatPreviewModeLiveAsWidgetCanvasPreview(): void
    {
        $source = $this->moduleFile('Service/SlotRendererService.php');

        self::assertStringContainsString('isEditorPreviewRequest', $source);
        self::assertStringNotContainsString(
            "(string)\$request->getParam('preview_mode', '') === 'live'",
            $source,
            'preview_mode=live must not trip isEditorPreviewRequest / force widget is-preview'
        );
        self::assertStringContainsString('theme-preview/content', $source);
        self::assertTrue(
            (bool)preg_match("/\\\$config\['preview_mode'\]\s*=\s*false/", $source)
            || (bool)preg_match("/'preview_mode'\s*=>\s*false/", $source),
            'theme-preview/content must force widget config preview_mode=false'
        );
    }

    public function testThemePreviewContentDoesNotAssignStringPreviewMode(): void
    {
        $source = $this->moduleFile('Controller/Frontend/ThemePreview/Content.php');
        self::assertStringContainsString("assign('layout_preview_mode'", $source);
        self::assertStringContainsString("assign('theme_preview_content', true)", $source);
        self::assertStringContainsString("assign('preview_mode', false)", $source);
        self::assertStringNotContainsString(
            "assign('preview_mode', (string)",
            $source,
            'string preview_mode=live must not be assigned (bool cast would enable is-preview)'
        );
    }

    public function testCategoryMenuKeepsChildrenForEditorFullPagePreview(): void
    {
        $source = $this->moduleFile('view/theme/frontend/widgets/navigation/category-menu/default.phtml');
        self::assertStringContainsString('$headerFlattenNavForEditor', $source);
        self::assertStringNotContainsString(
            '$navItems = $headerFlattenNavForEditor($navItems);',
            $source,
            'category-menu must not flatten children (kills hover mega panels)'
        );
    }

    public function testHeaderSidebarKeepsChildrenForEditorFullPagePreview(): void
    {
        $source = $this->moduleFile('view/theme/frontend/partials/header/default.phtml');
        self::assertStringContainsString('editor-preview-light', $source);
        self::assertStringNotContainsString(
            '$sidebarNavItems = $headerFlattenNavForEditor($sidebarNavItems);',
            $source,
            'header sidebar must not flatten children in editor/preview'
        );
    }

    public function testAccountDropdownHiddenOnlyInWidgetPreviewCanvas(): void
    {
        $source = $this->moduleFile('view/theme/frontend/widgets/header/account/default.phtml');
        self::assertStringContainsString('.widget-preview-canvas .<?= $wc ?> .account-dropdown', $source);
        self::assertStringNotContainsString(
            '.<?= $wc ?>.is-preview .account-dropdown',
            $source,
            'is-preview on full-page preview must not hide account dropdown'
        );
        self::assertTrue(
            (bool)preg_match(
                '/widget-preview-canvas[\s\S]{0,200}account-dropdown[\s\S]{0,200}display:\s*none/i',
                $source
            ),
            'canvas-only display:none for account-dropdown must remain'
        );
    }
}
