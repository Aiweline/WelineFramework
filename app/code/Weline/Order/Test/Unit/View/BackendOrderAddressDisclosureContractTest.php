<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderAddressDisclosureContractTest extends TestCase
{
    public function testEditTemplateUsesCollapsedDisclosureCardsForAddresses(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-shipping-card"', $src);
        self::assertStringContainsString('data-testid="order-edit-billing-card"', $src);
        self::assertStringContainsString('data-w-component="disclosure"', $src);
        self::assertStringContainsString('aria-expanded="false"', $src);
        self::assertStringContainsString('data-w-disclosure-panel hidden', $src);
        self::assertStringContainsString('data-testid="order-edit-shipping-toggle"', $src);
        self::assertStringContainsString('data-testid="order-edit-billing-toggle"', $src);
        self::assertStringNotContainsString('收货地址（当前）', $src);
        self::assertStringNotContainsString('账单地址（当前）', $src);
    }
}
