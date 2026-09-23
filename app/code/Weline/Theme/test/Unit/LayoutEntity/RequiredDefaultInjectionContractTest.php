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

    public function testWrongSlotIdentityIsRelocatedNotDuplicated(): void
    {
        $slots = [
            'content' => [[
                'node_uid' => 'drifted-filters-uid',
                'widget_module' => 'Weline_Filters',
                'widget_code' => 'category-filters',
                'slot_id' => 'content',
                'sort_order' => 0,
            ]],
        ];

        $merged = RequiredDefaultInjectionContract::merge($slots, 'category', [$this->filtersDeclaration()], []);

        self::assertArrayNotHasKey('content', $merged);
        self::assertCount(1, $merged['category-filters'] ?? []);
        self::assertSame('drifted-filters-uid', $merged['category-filters'][0]['node_uid'] ?? '');
        self::assertSame('category-filters', $merged['category-filters'][0]['slot_id'] ?? '');
    }

    public function testDualSlotSameIdentityCollapsesToDeclaredOnce(): void
    {
        $slots = [
            'content' => [[
                'node_uid' => 'old-content-copy',
                'widget_module' => 'Weline_Filters',
                'widget_code' => 'category-filters',
                'slot_id' => 'content',
            ]],
            'category-filters' => [[
                'node_uid' => 'bake-merger-copy',
                'widget_module' => 'Weline_Filters',
                'widget_code' => 'category-filters',
                'slot_id' => 'category-filters',
            ]],
        ];

        $merged = RequiredDefaultInjectionContract::merge($slots, 'category', [$this->filtersDeclaration()], []);

        self::assertArrayNotHasKey('content', $merged);
        self::assertCount(1, $merged['category-filters'] ?? []);
        self::assertSame('bake-merger-copy', $merged['category-filters'][0]['node_uid'] ?? '');
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
        self::assertStringNotContainsString("->append('',", $src);
        self::assertStringContainsString('->append($html,', $src);
    }

    public function testOverlayUsesPlanPipelineAndDedupsRegions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('SlotInventory', $src);
        self::assertStringContainsString('InjectionPlanner', $src);
        self::assertStringContainsString('One execute wave per depth', $src);
        self::assertStringContainsString('anyRegionHasWidget', $src);
        self::assertStringContainsString('pageHasWidgetBoundToSlot', $src);
        self::assertStringContainsString('pageHasWidgetPresent', $src);
        self::assertStringContainsString('outermostSlotRegions', $src);
        self::assertStringContainsString('required_default_injection_render_failed', $src);
        self::assertStringContainsString('data-required-injection-presence', $src);
        self::assertStringContainsString('ProductCardRenderer::resetProductCardCssEmission', $src);
        self::assertStringContainsString('resetPurchaseActionsAssetsEmission', $src);
        self::assertStringContainsString('unfilled soft-skip', $src);
        self::assertStringNotContainsString(
            "throw new \\RuntimeException(\n            'required_default_injection_unfilled:",
            $src,
        );
        self::assertStringContainsString('uninstalledInjectionsForVersion', $src);
        self::assertStringContainsString('REQ-THEME-0036', $src);
        self::assertStringContainsString('Identity XOR', $src);
        self::assertStringContainsString('unfilled must NOT 500', $src);
        self::assertStringContainsString('user_deleted@{versionId}', $src);
        self::assertStringNotContainsString('$html . $inner', $src);
        self::assertStringNotContainsString(
            "\$rendered .= SlotBoundaryMarkers::open(\$slotId)",
            $src,
        );
        self::assertStringNotContainsString("error_log('[RequiredDefaultInjection]", $src);
        self::assertStringNotContainsString('while ($pass < 16)', $src);
        self::assertStringNotContainsString('有槽才注', $src);
        self::assertStringNotContainsString('有槽必注', $src);
        self::assertStringContainsString('pageHasWidgetPresent', $src);
        self::assertStringContainsString('assertSlotHasAtMostOne', $src);
        self::assertStringContainsString('outermostSlotRegions', $src);
        // Duplicate is soft (log only): layout-owned chrome must drop default_injections JSON
        self::assertStringNotContainsString(
            "throw new \\RuntimeException(\n                'required_default_injection_duplicate:",
            $src,
        );
        self::assertStringContainsString('soft-skip', $src);
        self::assertStringContainsString('slotAllowsMultiple', $src);

        $contract = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionContract.php'
        );
        self::assertStringContainsString('function pageHasWidgetPresent', $contract);
        self::assertStringContainsString('function countWidgetPresent', $contract);
        self::assertStringContainsString('array_unique', $contract);
        self::assertStringContainsString('stripIgnoredPresenceRegions', $contract);
        self::assertStringContainsString('pageHasWidgetPresent / countWidgetPresent', $contract);
    }

    /**
     * 同部件 XOR：卸载仍走版本化 user_deleted；未填店面软跳过（见 Overlay soft-skip）。
     */
    public function testRequiredInjectionOmissionIsOnlyVersionedHumanUninstall(): void
    {
        $contract = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionContract.php'
        );
        self::assertStringContainsString('Identity XOR', $contract);
        self::assertStringContainsString('user_deleted@{versionId}', $contract);
        self::assertStringContainsString('missing fill must not 500', $contract);
        self::assertStringContainsString('must not be both layout-embedded', $contract);

        $planner = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionPlanner.php'
        );
        self::assertStringContainsString('isUninstalled($omissions', $planner);

        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
        self::assertStringContainsString('function uninstalledInjectionsForVersion', $service);
        self::assertStringContainsString('userDeletedSource($versionId)', $service);
        self::assertStringContainsString(
            'A plain `user_deleted` row without a version is not an uninstall of this version',
            $service,
        );

        $with = RequiredDefaultInjectionContract::merge([], 'checkout', [[
            'module' => 'Weline_Shipping',
            'type' => 'content',
            'code' => 'checkout-shipping-address',
            'default_injections' => [[
                'layout_type' => 'checkout',
                'layout_option' => 'default',
                'slot' => 'checkout-shipping-address',
                'area' => 'content',
                'sort_order' => 10,
                'required' => true,
            ]],
        ]], []);
        self::assertArrayHasKey('checkout-shipping-address', $with);
        self::assertSame('checkout-shipping-address', $with['checkout-shipping-address'][0]['widget_code']);

        $uninstalled = RequiredDefaultInjectionContract::merge([], 'checkout', [[
            'module' => 'Weline_Shipping',
            'type' => 'content',
            'code' => 'checkout-shipping-address',
            'default_injections' => [[
                'layout_type' => 'checkout',
                'layout_option' => 'default',
                'slot' => 'checkout-shipping-address',
                'area' => 'content',
                'sort_order' => 10,
                'required' => true,
            ]],
        ]], [[
            'slot_id' => 'checkout-shipping-address',
            'widget_module' => 'Weline_Shipping',
            'widget_code' => 'checkout-shipping-address',
        ]]);
        self::assertSame([], $uninstalled);
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
        // Soft path uses actual $pageType so requiredForPage inherits chrome-carrier
        // slots only — never force HOME (that plans content→newsletter onto policy).
        self::assertStringContainsString('fillRequiredDefaultsOnShell(', $src);
        self::assertStringContainsString('forcing HOME would plan content→newsletter', $src);
        self::assertStringContainsString('amazon-policy__', $src);
        self::assertStringContainsString('footer-*-links', $src);
        // HOME remains as last-resort safety-net detect only.
        self::assertStringContainsString('PAGE_TYPE_HOME', $src);
    }

    public function testNonHomepageInheritsHomepageChromeExtensionInjections(): void
    {
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-payment-account-links'));
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-help-links'));
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('header-nav-extensions'));
        self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer'));
        self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('list-filters'));

        $items = RequiredDefaultInjectionContract::requiredInjections([
            $this->filtersDeclaration(),
            $this->footerPaymentLinkDeclaration(),
        ], 'products');

        $codes = array_map(static fn(array $row): string => (string)$row['widget_code'], $items);
        self::assertContains('category-filters', $codes);
        self::assertContains('footer-payment-methods-link', $codes);

        $payment = null;
        foreach ($items as $row) {
            if (($row['widget_code'] ?? '') === 'footer-payment-methods-link') {
                $payment = $row;
                break;
            }
        }
        self::assertNotNull($payment);
        self::assertSame('footer-payment-account-links', $payment['slot_id']);
        self::assertSame('Weline_Payment', $payment['widget_module']);
    }

    public function testHomepageDoesNotDoubleCountChromeCarrierInjections(): void
    {
        $items = RequiredDefaultInjectionContract::requiredInjections([
            $this->footerPaymentLinkDeclaration(),
        ], 'homepage');
        self::assertCount(1, $items);
        self::assertSame('footer-payment-methods-link', $items[0]['widget_code']);
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

    /**
     * @return array<string, mixed>
     */
    private function footerPaymentLinkDeclaration(): array
    {
        return [
            'module' => 'Weline_Payment',
            'type' => 'footer',
            'code' => 'footer-payment-methods-link',
            'default_injections' => [[
                'layout_type' => 'homepage',
                'slot' => 'footer-payment-account-links',
                'area' => 'footer',
                'sort_order' => 0,
                'required' => true,
                'config' => ['label' => '支付方式'],
            ]],
        ];
    }
}
