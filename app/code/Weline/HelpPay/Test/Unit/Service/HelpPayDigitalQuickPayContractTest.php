<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contract: HelpPay pure-digital quick-buy aligns with Checkout virtual_only.
 */
final class HelpPayDigitalQuickPayContractTest extends TestCase
{
    public function testOrchestratorLocksVirtualOnlyLaneForDigital(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/HelpPayOrchestrator.php'
        );
        self::assertStringContainsString('offerShipping()', $src);
        self::assertStringContainsString('resolveRequiresShipping', $src);
        self::assertStringContainsString("'free_reason' => 'virtual_only'", $src);
        self::assertStringContainsString("'service_code' => 'none'", $src);
        self::assertStringContainsString('virtualOnlyShippingOption', $src);
        self::assertStringNotContainsString("product_type === 'travel_route'", $src);
        self::assertStringNotContainsString('travel_route', $src);
    }

    public function testQuoteServiceNeverHardDefaultsRequiresShippingTrueWithoutResolver(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/HelpPayQuickShippingQuoteService.php'
        );
        self::assertStringContainsString('HelpPayOfferShippingResolver', $src);
        self::assertStringContainsString('resolveRequiresShipping', $src);
        self::assertStringContainsString('lineRequiresShipping', $src);
        self::assertStringNotContainsString("'requires_shipping' => true,", $src);
    }

    public function testShareJsSkipsAddressAndShippingForDigital(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/js/helppay-share.js'
        );
        self::assertStringContainsString('function readRequiresShipping', $js);
        self::assertStringContainsString('function openQuickDigitalPaymentFlow', $js);
        self::assertStringContainsString('if (!readRequiresShipping(btn))', $js);
        self::assertStringContainsString("requires_shipping: false", $js);
        self::assertStringContainsString("service_code: 'none'", $js);
        self::assertStringNotContainsString('weight_minor: 500', $js);
    }

    public function testQuickPayWidgetProjectsRequiresShippingDataAttr(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-quick-pay.phtml'
        );
        self::assertStringContainsString('data-requires-shipping', $tpl);
        self::assertStringContainsString("\$offer['requires_shipping']", $tpl);
    }
}
