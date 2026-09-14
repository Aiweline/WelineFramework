<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * HelpPay quick-pay modal must lazy-load checkout address HTML (no PDP SSR).
 */
final class CheckoutRenderDeliveryAddressWidgetContractTest extends TestCase
{
    public function testProviderExposesLazyAddressWidgetOperation(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString("'renderDeliveryAddressWidget' => \$this->renderDeliveryAddressWidget(\$params)", $src);
        self::assertStringContainsString("'name' => 'renderDeliveryAddressWidget'", $src);
        self::assertStringContainsString("'frontend' => true", $src);
        self::assertStringContainsString('data-helppay-address-host', $src);
        self::assertStringContainsString(
            'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml',
            $src,
        );
        self::assertStringContainsString("'title' => (string) __('收货地址')", $src);
        self::assertStringContainsString('shipping_address_widget_unavailable', $src);
    }
}
