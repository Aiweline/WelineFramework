<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;

/**
 * Required default_injections must survive theme layout rewrites unless user_deleted.
 */
final class RequiredDefaultInjectionRuntimeSafetyNetContractTest extends TestCase
{
    public function testFloatHostSynthesizesWhenDesignFooterDroppedLayer(): void
    {
        $html = '<html><body><main>grocery homepage rewrite without floats</main></body></html>';
        self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($html));
        $out = StorefrontFloatLayerHost::ensureInHtml($html);
        self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($out));
        self::assertStringContainsString('data-slot-id="storefront-float-start"', $out);
        self::assertStringContainsString('data-slot-id="storefront-float-end"', $out);
        self::assertStringContainsString('</body>', $out);
    }

    public function testSourceTemplateRestoresWSlotFloatLayer(): void
    {
        $source = "<?php\n?>\n<w:slot id=\"footer\"></w:slot>\n";
        $out = StorefrontFloatLayerHost::ensureInSourceTemplate($source);
        self::assertStringContainsString('<w:slot id="storefront-float-start"', $out);
        self::assertStringContainsString('<w:slot id="storefront-float-end"', $out);
        // Idempotent
        self::assertSame($out, StorefrontFloatLayerHost::ensureInSourceTemplate($out));
    }

    public function testStorefrontFloatSlotsInheritOnNonHomepagePlans(): void
    {
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('storefront-float-start'));
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('storefront-float-end'));
        self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-help-links'));
        self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer'));

        $declarations = [[
            'module' => 'Weline_StoreMusic',
            'type' => 'content',
            'code' => 'store-music',
            'default_injections' => [[
                'layout_type' => 'homepage',
                'slot' => 'storefront-float-start',
                'required' => true,
            ]],
        ]];
        $targets = RequiredDefaultInjectionContract::requiredTargets($declarations, 'products');
        $slots = array_column($targets, 'slot_id');
        self::assertContains('storefront-float-start', $slots);
    }

    public function testWireUpUsesRuntimeSafetyNetAfterStrip(): void
    {
        $obs = dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php';
        $obsSrc = (string)file_get_contents($obs);
        self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $obsSrc);
        $stripPos = strpos($obsSrc, 'SlotBoundaryMarkers::strip($html)');
        $ensurePos = strpos($obsSrc, 'requiredNet->ensure($html)');
        self::assertNotFalse($stripPos);
        self::assertNotFalse($ensurePos);
        self::assertLessThan($ensurePos, $stripPos);

        $healer = dirname(__DIR__, 3) . '/Service/StorefrontSsrChromeHealer.php';
        $healerSrc = (string)file_get_contents($healer);
        self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $healerSrc);

        $materializer = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
        $matSrc = (string)file_get_contents($materializer);
        self::assertStringContainsString('StorefrontFloatLayerHost::ensureInSourceTemplate', $matSrc);

        $slotRenderer = dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        $srSrc = (string)file_get_contents($slotRenderer);
        self::assertStringContainsString('StorefrontFloatLayerHost::ensureInHtml', $srSrc);
    }

    public function testStoreMusicAndCsDeclareStarLayouts(): void
    {
        $music = dirname(__DIR__, 4) . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        if (!is_file($music)) {
            $music = dirname(__DIR__, 5) . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        }
        self::assertFileExists($music);
        $musicWidgets = require $music;
        $inj = $musicWidgets['store-music']['default_injections'][0] ?? [];
        self::assertSame('*', $inj['layout_type'] ?? null);
        self::assertTrue((bool)($inj['required'] ?? false));

        $cs = dirname(__DIR__, 4) . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        if (!is_file($cs)) {
            $cs = dirname(__DIR__, 5) . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        }
        self::assertFileExists($cs);
        $csWidgets = require $cs;
        $csInj = $csWidgets['customer-service-float']['default_injections'][0] ?? [];
        self::assertSame('*', $csInj['layout_type'] ?? null);
        self::assertTrue((bool)($csInj['required'] ?? false));
    }
}
