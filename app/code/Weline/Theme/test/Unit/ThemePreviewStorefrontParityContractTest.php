<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Visual-editor canvas = real storefront path + params.
 * Frontend theme-preview/content HTTP controller is deleted (no 302 / no shell).
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
        self::assertTrue(
            (bool)preg_match("/\\\$config\['preview_mode'\]\s*=\s*false/", $source)
            || (bool)preg_match("/'preview_mode'\s*=>\s*false/", $source),
            'editor/storefront preview must force widget config preview_mode=false'
        );
    }

    public function testFrontendThemePreviewContentControllerIsDeleted(): void
    {
        $path = dirname(__DIR__, 2) . '/Controller/Frontend/ThemePreview/Content.php';
        self::assertFileDoesNotExist($path, 'Frontend theme-preview/content HTTP shell must be fully deleted');
    }

    public function testLayoutPreviewFrontendRejectsWithoutRedirect(): void
    {
        $editor = $this->moduleFile('Controller/Backend/ThemeEditor.php');
        self::assertStringNotContainsString('redirectFrontendLayoutPreviewToStorefront', $editor);
        self::assertStringContainsString(
            'Frontend visual canvas must use the real storefront path',
            $editor
        );
        self::assertStringContainsString('renderFrontendLayoutTemplateHtml', $editor);
        self::assertStringContainsString(
            'Frontend layout HTML must use the real layout template path.',
            $editor
        );
        self::assertStringNotContainsString(
            "Weline_Theme::templates/frontend/theme-preview/content.phtml",
            $editor,
            'ThemeEditor must not fetch frontend theme-preview/content.phtml stub'
        );
        self::assertStringNotContainsString(
            "Weline_Theme::templates/backend/theme-preview/content.phtml",
            $editor
        );
        self::assertStringContainsString(
            'Weline_Theme::theme/backend/layouts/',
            $editor
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
