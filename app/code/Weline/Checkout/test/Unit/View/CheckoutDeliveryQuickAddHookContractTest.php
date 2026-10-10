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
        $this->assertStringContainsString('"layout_type":"homepage"', $content);
        $this->assertStringContainsString('"slot":"delivery"', $content);
        $this->assertStringContainsString("WidgetI18n::label(trim((string)(\$this->getData('title') ?? '')), '配送至')", $content);
    }

    public function testAddressListScrollsWhenExceedingFiveItems(): void
    {
        $widget = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        $content = (string) file_get_contents($widget);

        $this->assertStringContainsString('.address-list {', $content);
        $this->assertStringContainsString('max-height: calc((var(--address-item-h) * 5)', $content);
        $this->assertStringContainsString('overflow-y: auto', $content);
        $this->assertStringContainsString('overscroll-behavior: contain', $content);
    }

    public function testWidgetRegistryDeclaresDefaultInjectionWithoutThemeInline(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml'
        );
        self::assertStringContainsString('@widget.code {checkout-delivery-context}', $src);
        self::assertStringContainsString('@widget.slot {delivery}', $src);
        self::assertStringContainsString('"layout_type":"homepage"', $src);
        self::assertStringContainsString('"slot":"delivery"', $src);
        self::assertStringContainsString('"required":true', $src);
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

    public function testEditorPreviewKeepsQuickAddHookAndAutoDetectParity(): void
    {
        $widget = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        $content = (string) file_get_contents($widget);

        $this->assertStringContainsString('ensureLazyCaptcha', $content);
        $this->assertStringContainsString('getDeliveryCaptchaChallenge', $content);
        $this->assertStringContainsString('preview_storefront_delivery_parity', $content);
        $this->assertStringNotContainsString('$isEditorMode', $content);
        $this->assertStringNotContainsString("getParam('editor_mode'", $content);
        $this->assertStringNotContainsString('Theme editor iframe: skip session/DB', $content);
        $this->assertStringNotContainsString('if (!$isEditorMode)', $content);
        $this->assertStringContainsString(
            '<w:hook>Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add</w:hook>',
            $content
        );
        $this->assertStringContainsString('data-action="auto-detect"', $content);
        $this->assertStringContainsString('if ($autoDetect)', $content);
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$autoDetect\s*&&\s*!\$isEditorMode\s*\)/',
            $content,
            'auto-detect must not be gated on editor_mode'
        );
    }
}
