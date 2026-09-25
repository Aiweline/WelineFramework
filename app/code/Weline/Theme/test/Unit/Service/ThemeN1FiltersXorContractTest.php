<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\SlotBoundaryMarkers;

/**
 * N1: list Filters bake into published slots + runtime XOR/empty-slot only.
 */
final class ThemeN1FiltersXorContractTest extends TestCase
{
    public function testPublishedInnerDropsFilterPlaceholderWhenBakeHasFilters(): void
    {
        $bake = '<div class="theme-layout-entity-slot" data-slot-id="list-filters">'
            . '<div class="w-filters storefront-filters-panel" data-widget-code="category-filters">'
            . 'filters-ok</div></div>';
        $placeholder = '<div class="products-layout__placeholder slot-placeholder"'
            . ' data-placeholder="list-filters">'
            . '<span>筛选器区域 - 由 Filters 部件默认注入</span></div>';

        // Seed fragments so resolveBakeInner hits page_html.
        \Weline\Framework\Runtime\RequestContext::set(
            ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS,
            [
                'page_html' => '<!--@weline-slot:list-filters-->' . $bake . '<!--@/weline-slot:list-filters-->',
                'chrome_by_slot' => [],
            ],
        );
        \Weline\Framework\Runtime\RequestContext::set(
            ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE,
            false,
        );

        try {
            $out = ThemeLayoutEntityPublishedSlotHost::publishedInner('list-filters', $placeholder);
            self::assertStringContainsString('storefront-filters-panel', $out);
            self::assertStringContainsString('data-widget-code="category-filters"', $out);
            self::assertStringNotContainsString('data-placeholder="list-filters"', $out);
            self::assertStringNotContainsString('slot-placeholder', $out);
            self::assertTrue(ThemeLayoutEntityPublishedSlotHost::bakeInnerHasFiltersWidget($out));
            self::assertTrue(ThemeLayoutEntityPublishedSlotHost::isFilterInventorySlot('list-filters'));
            self::assertTrue(ThemeLayoutEntityPublishedSlotHost::isFilterInventorySlot('category-filters'));
            self::assertFalse(ThemeLayoutEntityPublishedSlotHost::isFilterInventorySlot('header'));
        } finally {
            \Weline\Framework\Runtime\RequestContext::remove(
                ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS,
            );
            \Weline\Framework\Runtime\RequestContext::remove(
                ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE,
            );
        }
    }

    public function testSlotFillerNarrowHealPrefersBakeSpliceThenXorAllowlist(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertStringContainsString('function splicePublishedFilterSlotsFromBake', $src);
        self::assertStringContainsString('function stripFilterDeclarationPlaceholdersIfPresent', $src);
        $healStart = \strpos($src, 'function healNarrowFilterPlaceholders');
        self::assertNotFalse($healStart);
        $healEnd = \strpos($src, 'function shouldPreferNarrowFilterHeal', $healStart);
        self::assertNotFalse($healEnd);
        $healBody = \substr($src, $healStart, $healEnd - $healStart);
        self::assertStringContainsString('splicePublishedFilterSlotsFromBake', $healBody);
        self::assertStringContainsString("'list-filters', 'category-filters'", $healBody);
        $splicePos = \strpos($healBody, 'splicePublishedFilterSlotsFromBake');
        $fillPos = \strpos($healBody, 'fillRequiredDefaultsOnShell');
        self::assertNotFalse($splicePos);
        self::assertNotFalse($fillPos);
        self::assertLessThan($fillPos, $splicePos, 'bake splice must run before Overlay allowlist');
    }

    public function testOverlayAppendAcceptsSlotAllowlist(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('?array $slotAllowlist = null', $src);
        self::assertStringContainsString('Identity XOR still skips present widgets', $src);
    }

    public function testBakeCoordinatorFinalizesListFilterSlots(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );
        self::assertStringContainsString('finalizePublishedListFilterSlots', $src);
        self::assertStringContainsString('theme_layout_entity_list_filters_bake_missing', $src);
        self::assertStringContainsString('Weline_Filters', $src);
        $docStart = \strpos($src, 'N1 write-side gate for products/category/search');
        self::assertNotFalse($docStart);
        $fnEnd = \strpos($src, 'function rebakeAfterInjectionCollect', $docStart);
        self::assertNotFalse($fnEnd);
        $body = \substr($src, $docStart, $fnEnd - $docStart);
        self::assertStringContainsString('widget seat wake not required', $body);
        self::assertStringContainsString('category-filters', $body);
        self::assertStringNotContainsString('default_injections_json', $body);
    }

    public function testCompleteShellStillSkipsOverlayOnSkipFill(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );
        $skipPos = \strpos($src, "\$reason .= '+skip_fill_solidified'");
        self::assertNotFalse($skipPos);
        $stripPos = \strpos($src, 'SlotBoundaryMarkers::strip', $skipPos);
        self::assertNotFalse($stripPos);
        $between = \substr($src, $skipPos, $stripPos - $skipPos);
        self::assertStringNotContainsString('fillRequiredDefaultsOnShell', $between);
        self::assertStringContainsString('布局固化与默认注入', $between);
    }

    public function testHealedFiltersShellNeedsNoSafetyNet(): void
    {
        $healed = '<!--@weline-slot:list-filters-->'
            . '<div class="theme-layout-entity-slot" data-slot-id="list-filters">'
            . '<div class="w-filters storefront-filters-panel" data-widget-code="category-filters">'
            . 'filters-ok</div></div>'
            . '<!--@/weline-slot:list-filters-->';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($healed)
        );
        $outbound = SlotBoundaryMarkers::strip($healed);
        self::assertStringContainsString('storefront-filters-panel', $outbound);
        self::assertStringNotContainsString('data-placeholder="list-filters"', $outbound);
    }
}
