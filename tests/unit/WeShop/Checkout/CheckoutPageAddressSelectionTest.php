<?php

declare(strict_types=1);

namespace Tests\Unit\WeShop\Checkout;

use PHPUnit\Framework\TestCase;

final class CheckoutPageAddressSelectionTest extends TestCase
{
    public function testSavedAddressSelectionControlsNewAddressFormVisibility(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/code/WeShop/Checkout/view/templates/frontend/checkout/index.phtml'
        );

        self::assertStringContainsString('$selectedSavedAddressId = 0;', $template);
        self::assertStringContainsString('$showNewAddressFields = $isGuestCheckout || $selectedSavedAddressId <= 0;', $template);
        self::assertStringContainsString(
            'data-weshop-new-address-grid<?= $showNewAddressFields ? \'\' : \' hidden\' ?>',
            $template
        );
        self::assertStringContainsString('newAddressGrid.hidden = useSavedAddress && !isGuestCheckoutMode();', $template);
        self::assertStringContainsString('var effectiveSelectedAddressId = selectedAddressExists ? selectedAddressId : defaultAddressId;', $template);
        self::assertStringContainsString('value="0" <?= $showNewAddressFields ? \'checked\' : \'\' ?>>', $template);
    }
}
