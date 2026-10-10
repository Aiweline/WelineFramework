<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutTaxIdentityWidgetContractTest extends TestCase
{
    public function testWidgetInjectsCheckoutTaxIdentitySlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Tax/widget.php';
        $tpl = 'Weline_Tax::templates/frontend/widgets/checkout-tax-identity.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-tax-identity.phtml');
        self::assertStringContainsString('@widget.code {checkout-tax-identity}', $src);
        self::assertStringContainsString('@widget.slot {checkout-tax-identity}', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"checkout-tax-identity"', $src);
        self::assertStringContainsString('"required":true', $src);
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
