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

    public function testBodyEndKeepsFloatLayerJsLoaderWithoutPseudoSlots(): void
    {
        $hook = (string)file_get_contents(
            $this->themeRoot() . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString('storefrontFloatLayer', $hook);
        self::assertStringContainsString('storefrontImageFallback', $hook);
        self::assertStringNotContainsString('id="w-storefront-float-layer"', $hook);
        self::assertStringNotContainsString('getHook(', $hook);
        self::assertStringNotContainsString('float-slot-start', $hook);
        self::assertStringNotContainsString('float-slot-end', $hook);
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
