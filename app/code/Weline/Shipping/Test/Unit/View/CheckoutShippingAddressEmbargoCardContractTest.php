<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingAddressEmbargoCardContractTest extends TestCase
{
    public function testSavedAddressCardMarksEmbargoWithDangerTokens(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/widgets/checkout-shipping-address.css',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/checkout-shipping-address.js',
        );

        self::assertStringContainsString('is-embargoed', $tpl);
        self::assertStringContainsString('data-address-embargo', $tpl);
        self::assertStringContainsString('embargo_hint', $tpl);
        self::assertStringContainsString('.w-shipping-checkout-address__card.is-embargoed', $css);
        self::assertStringContainsString('--color-danger', $css);
        self::assertStringContainsString('applyEmbargoOnCard', $js);
        self::assertStringContainsString('embargo_blocked', $js);
        self::assertStringContainsString('selectedEmbargoCard', $js);
        self::assertStringContainsString("data-embargo-blocked') !== '1'", $js);
        self::assertStringContainsString('skipRequired', $js);
    }
}
