<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * user-area 等多部件槽：布局注入（如 wishlist-icon）不得整槽替换模板 hook/兄弟 HTML。
 */
final class SlotRendererCowMultipleSlotContractTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/'));
        self::assertIsString($content);

        return $content;
    }

    public function testMultipleSlotCowUsesInPlaceSpliceInsteadOfExclusiveReplace(): void
    {
        $src = $this->read('Service/SlotRendererService.php');
        self::assertStringContainsString('function spliceCowMergedMultipleSlotInner(', $src);
        self::assertStringContainsString('function collectCowTombstoneRefs(', $src);
        self::assertStringContainsString('function hydrateEmptyTemplateWidgetBlocks(', $src);
        self::assertStringContainsString('function replaceExistingSlotWidgetMarkupByCode(', $src);
        self::assertStringContainsString('function prependCowLayoutAdditionBeforeFirstTemplateWidget(', $src);
        self::assertStringContainsString('header-wishlist', $src);
        self::assertStringContainsString("\$widgetCode === 'wishlist-icon'", $src);
        self::assertStringContainsString('if ($this->isMultipleSlotWrapperTag($wrapperOpenTag))', $src);
        self::assertStringNotContainsString('function isMultipleSlotElement(', $src);
        self::assertStringNotContainsString('function processSlotFragmentWithDom(', $src);
        self::assertStringContainsString('assertSlotBoundaryMarkersPresent', $src);
    }

    public function testHeaderUserAreaSlotIsMultiple(): void
    {
        $header = $this->read('view/theme/frontend/partials/header/default.phtml');
        self::assertStringContainsString('<w:slot id="user-area"', $header);
        self::assertStringContainsString('multiple="true"', $header);
        self::assertStringContainsString('mini-cart-icon', $header);
        self::assertStringContainsString('wishlist-icon', $header);
        self::assertStringContainsString('<w:hook>header-wishlist-icon</w:hook>', $header);
        self::assertStringContainsString('退货', $header);
    }

    public function testWidgetLibraryTabSwitchReloadsWidgetCatalog(): void
    {
        foreach ([
            'view/statics/ui/pages/weline-theme-editor.js',
            'view/statics/js/theme-editor.js',
        ] as $path) {
            $editor = $this->read($path);
            self::assertStringContainsString('syncLibraryTypeFilterChipsFromTab', $editor, $path);
            self::assertStringContainsString('previousMode === \'applications\'', $editor, $path);
            self::assertStringContainsString('reloadWidgetLibrary({ silent: true })', $editor, $path);
            self::assertStringNotContainsString('previousTab !== state.widgetLibraryTab', $editor, $path);
        }
    }
}
