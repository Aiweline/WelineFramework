<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutTaxIdentityWidgetContractTest extends TestCase
{
    public function testWidgetInjectsCheckoutTaxIdentitySlot(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Tax/widget.php';
        self::assertArrayHasKey('checkout-tax-identity', $widgets);
        $w = $widgets['checkout-tax-identity'];
        self::assertSame('checkout-tax-identity', $w['slot'] ?? null);
        $inj = $w['default_injections'][0] ?? [];
        self::assertSame('checkout', $inj['layout_type'] ?? null);
        self::assertSame('checkout-tax-identity', $inj['slot'] ?? null);
        self::assertTrue((bool)($inj['required'] ?? false));
    }

    public function testTemplateIsCollapsedDisclosure(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-tax-identity.phtml'
        );
        self::assertStringContainsString('data-tax-identity-root', $tpl);
        self::assertStringContainsString('data-tax-identity-details', $tpl);
        self::assertStringContainsString('name="tax_identity[tax_id]"', $tpl);
        self::assertStringContainsString('需要填写税号', $tpl);
        self::assertStringContainsString("root.closest('[data-tax-identity-host]')", $tpl);
        self::assertStringContainsString('host.hidden = !show', $tpl);
        self::assertStringContainsString('data-shipping-address-cascade', $tpl);
        self::assertStringContainsString('checkout-shipping-address', $tpl);
        self::assertStringContainsString('weline:address:multi-change', $tpl);
        self::assertStringContainsString('function iso2', $tpl);
        self::assertStringContainsString('function countryFromSelectedCard', $tpl);
        self::assertStringContainsString('data-address-json', $tpl);
        self::assertStringContainsString('shippingEditorOpen', $tpl);
        // Must not use bare document.querySelector('[name="country_code"]') —
        // header delivery picker also owns that name and would pin VAT hidden.
        self::assertStringNotContainsString(
            "document.querySelector('[name=\"country_code\"]')",
            $tpl
        );
    }
}
