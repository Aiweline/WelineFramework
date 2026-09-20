<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;

final class RequiredDefaultInjectionContractTest extends TestCase
{
    public function testMissingRequiredInjectionIsMerged(): void
    {
        $slots = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration()], []);

        self::assertArrayHasKey('list-filters', $slots);
        self::assertSame('category-filters', $slots['list-filters'][0]['widget_code']);
        self::assertSame('Weline_Filters', $slots['list-filters'][0]['widget_module']);
    }

    public function testPresentWidgetIsNotDuplicated(): void
    {
        $slots = [
            'list-filters' => [[
                'widget_module' => 'Weline_Filters',
                'widget_code' => 'category-filters',
                'sort_order' => 0,
            ]],
        ];

        $merged = RequiredDefaultInjectionContract::merge($slots, 'products', [$this->filtersDeclaration()], []);

        self::assertCount(1, $merged['list-filters']);
    }

    public function testEmptyModuleSeedDoesNotSuppressRequiredModule(): void
    {
        $slots = [
            'list-filters' => [[
                'widget_module' => '',
                'widget_code' => 'category-filters',
                'sort_order' => 0,
            ]],
        ];

        $merged = RequiredDefaultInjectionContract::merge($slots, 'products', [$this->filtersDeclaration()], []);

        self::assertCount(2, $merged['list-filters']);
        self::assertSame('Weline_Filters', $merged['list-filters'][1]['widget_module']);
    }

    public function testVersionUninstallSuppressesOnlyThatVersion(): void
    {
        self::assertSame('user_deleted@89', RequiredDefaultInjectionContract::userDeletedSource(89));
        self::assertTrue(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted@89', 89));
        self::assertFalse(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted@89', 90));
        self::assertFalse(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted', 89));

        $omitted = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration()], [[
            'slot_id' => 'list-filters',
            'widget_module' => 'Weline_Filters',
            'widget_code' => 'category-filters',
        ]]);
        self::assertSame([], $omitted);

        $otherVersion = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration()], []);
        self::assertArrayHasKey('list-filters', $otherVersion);
    }

    public function testRecommendedInjectionIsNotPromoted(): void
    {
        $declaration = $this->filtersDeclaration();
        $declaration['default_injections'][1]['required'] = false;

        $slots = RequiredDefaultInjectionContract::merge([], 'products', [$declaration], []);

        self::assertArrayNotHasKey('list-filters', $slots);
        self::assertArrayHasKey('category-filters', RequiredDefaultInjectionContract::merge([], 'category', [$declaration], []));
    }

    public function testRequiredTargetsListsSlotModuleCode(): void
    {
        $targets = RequiredDefaultInjectionContract::requiredTargets([$this->filtersDeclaration()], 'products');

        self::assertCount(1, $targets);
        self::assertSame('list-filters', $targets[0]['slot_id']);
        self::assertSame('Weline_Filters', $targets[0]['widget_module']);
        self::assertSame('category-filters', $targets[0]['widget_code']);
        self::assertArrayNotHasKey('node', $targets[0]);

        $items = RequiredDefaultInjectionContract::requiredInjections([$this->filtersDeclaration()], 'products');
        self::assertCount(1, $items);
        self::assertArrayHasKey('node', $items[0]);
        self::assertTrue(RequiredDefaultInjectionContract::isUninstalled([[
            'slot_id' => 'list-filters',
            'widget_module' => 'Weline_Filters',
            'widget_code' => 'category-filters',
        ]], 'list-filters', 'Weline_Filters', 'category-filters'));
    }

    public function testSlotInnerHasWidgetCodeRequiresExplicitMarkers(): void
    {
        self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<div data-widget-code="category-filters"></div>',
            'Weline_Filters',
            'category-filters',
        ));
        self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<button data-testid="product-add-to-cart">Add</button>',
            'Weline_Cart',
            'product-add-to-cart',
        ));
        // Loose action/class markers must not suppress required injection.
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<button data-action="add">Add</button>',
            'Weline_Cart',
            'product-add-to-cart',
        ));
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<button data-action="buy-now">Buy</button>',
            'Weline_Checkout',
            'product-buy-now',
        ));
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<div class="w-payment-express"></div>',
            'Weline_Payment',
            'product-express-payment',
        ));
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<div class="empty"></div>',
            'Weline_Cart',
            'product-add-to-cart',
        ));
    }

    public function testSlotFillerUsesGenericShellMissingRequiredInjections(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php');
        self::assertStringContainsString('shellMissingRequiredInjections', $src);
        self::assertStringNotContainsString('shellMissingRequiredPurchaseWidgets', $src);
        self::assertStringContainsString('RequiredDefaultInjectionContract::requiredTargets', $src);
        self::assertStringContainsString('anySlotRegionMissingWidget', $src);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', $src);
        self::assertStringContainsString('required_default_injection_shell_scan_failed', $src);
        // Entity-missing path must overlay the shell HTML, never append('').
        self::assertStringNotContainsString("->append('',", $src);
        self::assertStringContainsString('->append($html,', $src);
    }

    public function testOverlayZeroToleranceThrowsOnRenderFailureAndFillsAllRegions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('有部件必入声明槽', $src);
        self::assertStringContainsString('Multi-pass', $src);
        self::assertStringContainsString('listSlotRegions', $src);
        self::assertStringContainsString('required_default_injection_render_failed', $src);
        self::assertStringContainsString('required_default_injection_ensure_failed', $src);
        self::assertStringContainsString('required_default_injection_unfilled', $src);
        self::assertStringContainsString('requiredInjections', $src);
        // Destination absent: continue (no ghost); never append open markers at end.
        self::assertStringContainsString('Destination not in tree yet', $src);
        self::assertStringNotContainsString(
            "\$rendered .= SlotBoundaryMarkers::open(\$slotId)",
            $src,
        );
        self::assertStringNotContainsString("error_log('[RequiredDefaultInjection]", $src);
        self::assertStringNotContainsString('有槽才注', $src);
        self::assertStringNotContainsString('有槽必注', $src);
    }

    public function testStorefrontFillerLoadsSolidifiedPhtmlNotRequestInjection(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php');
        self::assertStringContainsString('function includeEntityPhtml', $src);
        self::assertStringContainsString('RequiredDefaultInjectionStorefrontOverlay', $src);
        self::assertStringContainsString('ThemeLayout::STATUS_PUBLISHED', $src);
    }

    public function testLayoutSlotRendererSoftPathStillRunsRequiredOverlay(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php');
        self::assertStringContainsString('fillRequiredDefaultsOnShell', $src);
        self::assertStringContainsString('required_default_injection_failed', $src);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersDeclaration(): array
    {
        return [
            'module' => 'Weline_Filters',
            'type' => 'sidebar',
            'code' => 'category-filters',
            'default_injections' => [
                [
                    'layout_type' => 'category',
                    'layout_option' => 'default',
                    'slot' => 'category-filters',
                    'area' => 'sidebar',
                    'sort_order' => 0,
                    'required' => true,
                ],
                [
                    'layout_type' => 'products',
                    'layout_option' => 'default',
                    'slot' => 'list-filters',
                    'area' => 'sidebar',
                    'sort_order' => 0,
                    'required' => true,
                ],
            ],
        ];
    }
}
