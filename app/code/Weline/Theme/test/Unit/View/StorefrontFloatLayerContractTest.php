<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class StorefrontFloatLayerContractTest extends TestCase
{
    private function themeRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testFooterPartialEmitsEditableFloatSlots(): void
    {
        $footer = (string)file_get_contents(
            $this->themeRoot() . '/view/theme/frontend/partials/footer/default.phtml'
        );
        self::assertStringContainsString('id="w-storefront-float-layer"', $footer);
        self::assertStringContainsString('id="storefront-float-start"', $footer);
        self::assertStringContainsString('id="storefront-float-end"', $footer);
        self::assertStringContainsString('data-float-slot="start"', $footer);
        self::assertStringContainsString('data-float-slot="end"', $footer);
        self::assertStringContainsString('data-testid="storefront-float-start"', $footer);
        self::assertStringContainsString('data-testid="storefront-float-end"', $footer);
        self::assertStringContainsString('<w:slot id="storefront-float-start"', $footer);
        self::assertStringContainsString('<w:slot id="storefront-float-end"', $footer);
        self::assertStringContainsString('accept="store-music,layout-storefront-float-start,content"', $footer);
        self::assertStringContainsString('accept="customer-service-float,layout-storefront-float-end,content"', $footer);
        self::assertStringContainsString('--weline-product-sticky-purchase-clearance', $footer);
        self::assertStringContainsString('#w-storefront-float-layer{', $footer);
        self::assertStringContainsString('bottom:var(--weline-product-sticky-purchase-clearance,0px)', $footer);
        self::assertStringContainsString('padding-bottom:0', $footer);
        self::assertStringNotContainsString('getHook(', $footer);
        self::assertStringNotContainsString('float-slot-start', $footer);
        self::assertStringNotContainsString('float-slot-end', $footer);
    }

    public function testBodyEndKeepsImageFallbackIdleWithoutFloatPseudoSlots(): void
    {
        $hook = (string)file_get_contents(
            $this->themeRoot() . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        // Float JS must not ride a hidden host (IO never intersects display:none).
        self::assertStringNotContainsString('storefrontFloatLayer', $hook);
        self::assertStringContainsString('storefrontImageFallback', $hook);
        self::assertStringContainsString('data-weline-load-when="idle"', $hook);
        self::assertStringContainsString('storefront-float-load-on-layer-v1', $hook);
        self::assertStringNotContainsString('id="w-storefront-float-layer"', $hook);
        self::assertStringNotContainsString('getHook(', $hook);
        self::assertStringNotContainsString('float-slot-start', $hook);
        self::assertStringNotContainsString('float-slot-end', $hook);
    }

    public function testFooterFloatLayerHostsModuleLoad(): void
    {
        $footer = (string)file_get_contents(
            $this->themeRoot() . '/view/theme/frontend/partials/footer/default.phtml'
        );
        self::assertStringContainsString('data-weline-load="storefrontFloatLayer"', $footer);
        self::assertStringContainsString('data-testid="storefront-float-layer"', $footer);
        self::assertMatchesRegularExpression(
            '/id="w-storefront-float-layer"[^>]*data-weline-load="storefrontFloatLayer"/s',
            $footer
        );

        $host = (string)file_get_contents(
            $this->themeRoot() . '/Service/LayoutEntity/StorefrontFloatLayerHost.php'
        );
        self::assertStringContainsString('data-weline-load="storefrontFloatLayer"', $host);
    }

    public function testFloatLayerCssOwnsStickyClearanceOnce(): void
    {
        $css = (string)file_get_contents(
            $this->themeRoot() . '/view/statics/css/storefront-float-layer.css'
        );
        self::assertStringContainsString('.w-storefront-float-layer', $css);
        self::assertStringContainsString('--weline-product-sticky-purchase-clearance', $css);
        self::assertStringContainsString('bottom: var(--weline-product-sticky-purchase-clearance, 0px)', $css);
        self::assertStringNotContainsString('padding-bottom: var(--weline-product-sticky-purchase-clearance', $css);
        self::assertStringContainsString('__slot--start', $css);
        self::assertStringContainsString('__slot--end', $css);
        self::assertStringContainsString('position: relative !important', $css);
        self::assertStringContainsString('storefront-float-edge-dock-v1', $css);
        self::assertStringContainsString('storefront-float-edge-flush-v2', $css);
        self::assertStringContainsString('storefront-float-edge-transform-clear-v1', $css);
        self::assertStringContainsString('is-edge-collapsed', $css);
        self::assertStringContainsString('.w-storefront-float-edge__dismiss', $css);
        self::assertStringContainsString('storefront-float-dismiss-contrast-v1', $css);
        self::assertStringContainsString('.w-storefront-float-edge__recall', $css);
        self::assertStringContainsString('visibility: hidden !important', $css);
        self::assertStringContainsString("left: max(0px, env(safe-area-inset-left, 0px)) !important", $css);
        // Expanded slots must not advertise will-change:transform (fixed CB).
        self::assertDoesNotMatchRegularExpression(
            '/\.w-storefront-float-layer__slot\s*\{[^}]*will-change:\s*transform/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.w-storefront-float-layer__slot\.is-edge-collapsed\s*\{[^}]*will-change:\s*transform/s',
            $css
        );
    }

    public function testFloatLayerJsOwnsEdgeDockLocalStorage(): void
    {
        $js = (string)file_get_contents(
            $this->themeRoot() . '/view/statics/js/storefront-float-layer.js'
        );
        self::assertStringContainsString('storefront-float-edge-dock-v1', $js);
        self::assertStringContainsString('storefront-float-edge-transform-clear-v1', $js);
        self::assertStringContainsString('weline.storefrontFloat.edgeCollapsed.', $js);
        self::assertStringContainsString('localStorage', $js);
        self::assertStringContainsString('data-float-edge-dismiss', $js);
        self::assertStringContainsString('data-float-edge-recall', $js);
        self::assertStringContainsString('is-edge-collapsed', $js);
        self::assertStringContainsString('translateX(-100%)', $js);
        self::assertStringContainsString('translateX(100%)', $js);
        self::assertStringContainsString('removeProperty(\'transform\')', $js);
        self::assertStringContainsString('bindCsOpenClearsEndTransform', $js);
        self::assertStringContainsString('endHasOpenCustomerService', $js);
        self::assertStringNotContainsString("style.transform = 'translateX(0)'", $js);
        self::assertStringNotContainsString('style.transform = "translateX(0)"', $js);
    }

    public function testFooterPartialWiresEdgeDockLabels(): void
    {
        $footer = (string)file_get_contents(
            $this->themeRoot() . '/view/theme/frontend/partials/footer/default.phtml'
        );
        self::assertStringContainsString('data-edge-dismiss-label', $footer);
        self::assertStringContainsString('data-edge-recall-label', $footer);
        self::assertStringContainsString("__('收起悬浮')", $footer);
        self::assertStringContainsString("__('展开悬浮')", $footer);
        self::assertStringContainsString('is-edge-collapsed', $footer);
        self::assertStringContainsString('storefront-float-edge-transform-clear-v1', $footer);
        self::assertStringContainsString('will-change:auto', $footer);
    }

    public function testHookRegistryDropsFloatPseudoSlots(): void
    {
        $hooks = include $this->themeRoot() . '/hook.php';
        self::assertIsArray($hooks);
        self::assertArrayNotHasKey('Weline_Theme::frontend::layouts::base::float-slot-start', $hooks);
        self::assertArrayNotHasKey('Weline_Theme::frontend::layouts::base::float-slot-end', $hooks);
        self::assertArrayHasKey('Weline_Theme::frontend::layouts::base::body-end', $hooks);
    }

    public function testSharedChromeRegistersFooterFloatSlots(): void
    {
        $src = (string)file_get_contents($this->themeRoot() . '/Service/SharedChromeService.php');
        self::assertStringContainsString('FOOTER_NESTED_CHROME_SLOTS', $src);
        self::assertStringContainsString("'storefront-float-start'", $src);
        self::assertStringContainsString("'storefront-float-end'", $src);

        $service = new \Weline\Theme\Service\SharedChromeService(
            $this->createMock(\Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface::class),
        );
        self::assertTrue($service->isChromeSlot('storefront-float-start'));
        self::assertTrue($service->isChromeSlot('storefront-float-end'));
        self::assertTrue($service->isChromeTarget('', 'storefront-float-start'));
    }

    public function testModulesRegisterFloatLayerLoader(): void
    {
        $modules = (string)file_get_contents(
            $this->themeRoot() . '/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('storefrontFloatLayer', $modules);
        self::assertStringContainsString('storefront-float-layer.js', $modules);
    }
}
