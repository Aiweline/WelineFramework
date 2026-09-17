<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CheckoutDeliveryPurposeFilterContractTest extends TestCase
{
    public function testContextResolvesAddressPurposeAndSaveMeta(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php'
        );
        self::assertStringContainsString('resolveAddressPurpose', $src);
        self::assertStringContainsString('extractPurposeMeta', $src);
        self::assertStringContainsString("filters['purpose_checkout'] = 1", $src);
        self::assertStringContainsString("filters['purpose_receiving'] = 1", $src);
        self::assertStringContainsString("'address_purpose' => \$listPurpose", $src);
        self::assertStringContainsString('guestMatchesPurpose', $src);
        self::assertStringContainsString('stripCheckoutPurposeForCustomer', $src);
        self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);
    }

    public function testClearDeliveryBookStripsCustomerCheckoutPurpose(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php'
        );
        self::assertStringContainsString('stripCheckoutPurposeForCustomer', $src);
        self::assertStringContainsString('仅清空结账用途地址', $src);
        self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);

        $service = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/Service/DeliveryAddressService.php'
        );
        self::assertStringContainsString('function stripCheckoutPurposeForCustomer', $service);
        self::assertStringContainsString('function ensureCheckoutPurposeForAddressId', $service);
        self::assertStringContainsString("schema_fields_PURPOSE_CHECKOUT, 0", $service);
    }

    public function testSubmitV2PromotesSelectedAddressToCheckout(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);
    }

    public function testCheckoutWidgetSeedsCheckoutPurpose(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml'
        );
        self::assertStringContainsString("getContext(['address_purpose' => 'checkout'])", $tpl);
        self::assertStringContainsString('data-also-use-receiving', $tpl);
    }

    public function testQueryProviderDeclaresPurposeParams(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString("'address_purpose'", $src);
        self::assertStringContainsString("'purpose_source'", $src);
        self::assertStringContainsString("'also_use_receiving'", $src);
        self::assertStringContainsString("'also_use_checkout'", $src);
    }
}
