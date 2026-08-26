<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutDeliveryQuickAddHookContractTest extends TestCase
{
    public function testWidgetDelegatesQuickAddToShippingHook(): void
    {
        $widget = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        $this->assertFileExists($widget);
        $content = (string) file_get_contents($widget);

        $this->assertStringContainsString(
            '<w:hook>Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add</w:hook>',
            $content
        );
        $this->assertStringNotContainsString('<form data-quick-add-form', $content);
        $this->assertStringNotContainsString('<w:form', $content);
        $this->assertStringNotContainsString('<input name="province"', $content);
        $this->assertStringNotContainsString('<input name="city"', $content);
        $this->assertStringContainsString('WelineThemeAddress.applyValues', $content);
        $this->assertStringContainsString('@widget.default_injections', $content);
        $this->assertStringContainsString('"layout_type":"*"', $content);
        $this->assertStringContainsString('"slot":"delivery"', $content);
        $this->assertStringContainsString("(string)__(trim((string)(\$this->getData('title') ?? '配送至')))", $content);
    }

    public function testWidgetRegistryDeclaresDefaultInjectionWithoutThemeInline(): void
    {
        $registry = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $this->assertFileExists($registry);
        $widgets = require $registry;
        $this->assertIsArray($widgets);
        $this->assertArrayHasKey('checkout-delivery-context', $widgets);
        $entry = $widgets['checkout-delivery-context'];
        $this->assertSame('header', $entry['type'] ?? null);
        $this->assertSame('delivery', $entry['slot'] ?? null);
        $this->assertNotEmpty($entry['default_injections'] ?? []);
        $injection = $entry['default_injections'][0];
        $this->assertSame('*', $injection['layout_type'] ?? null);
        $this->assertSame('delivery', $injection['slot'] ?? null);
        $this->assertSame('header', $injection['area'] ?? null);

        $header = dirname(__DIR__, 4) . '/Theme/view/theme/frontend/partials/header/default.phtml';
        $this->assertFileExists($header);
        $headerSource = (string) file_get_contents($header);
        $this->assertDoesNotMatchRegularExpression(
            '/<w:widget[^>]*(checkout-delivery-context|name="checkout-delivery-context")/i',
            $headerSource
        );
        $this->assertStringContainsString('id="delivery"', $headerSource);
    }

    public function testHookRegistryPublishesQuickAddExtensionPoint(): void
    {
        $hook = dirname(__DIR__, 3) . '/hook.php';
        $this->assertFileExists($hook);
        $content = (string) file_get_contents($hook);

        $this->assertStringContainsString(
            "Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add",
            $content
        );
    }

    public function testCaptchaGuardIntentMatchesForm(): void
    {
        $guard = dirname(__DIR__, 3) . '/Service/DeliveryAddressCaptchaGuard.php';
        $this->assertFileExists($guard);
        $content = (string) file_get_contents($guard);

        $this->assertStringContainsString("INTENT = 'checkout.save_delivery_address'", $content);
        $this->assertStringContainsString("FORM_ID = 'checkout-delivery-quick-add-form'", $content);
    }

    public function testEditorModeLightPathSkipsHeavyContextAndQuickAddHook(): void
    {
        $widget = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        $content = (string) file_get_contents($widget);

        $this->assertStringContainsString('ensureLazyCaptcha', $content);
        $this->assertStringContainsString('getDeliveryCaptchaChallenge', $content);
        $this->assertStringContainsString("getParam('editor_mode'", $content);
        $this->assertStringContainsString('if ($isEditorMode)', $content);
        $this->assertStringContainsString('if (!$isEditorMode)', $content);
        $this->assertStringContainsString(
            '<w:hook>Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add</w:hook>',
            $content
        );
        $this->assertStringContainsString('Theme editor iframe: skip session/DB', $content);
    }
}
