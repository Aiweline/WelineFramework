<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Late mount for HelpPay lazy address fragment (script may load before HTML inject).
 */
final class CheckoutShippingAddressMountContractTest extends TestCase
{
    public function testVersionedWidgetExportsMountAndGuardsDoubleBind(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/view/statics/js/widgets/checkout-shipping-address.js',
        );
        self::assertStringContainsString('function mount(root)', $src);
        self::assertStringContainsString("data-shipping-mounted", $src);
        self::assertStringContainsString('mount: mount', $src);
        self::assertStringContainsString(
            "document.querySelector('[data-shipping-checkout-address]')",
            $src,
        );
        self::assertStringContainsString('20260914-picker-all-addr1', $src);
        self::assertStringContainsString('list_all_addresses', $src);
        self::assertStringContainsString('upsertLocalSavedAddress', $src);
        self::assertStringContainsString('syncChangeAddressLabel', $src);
    }
}
