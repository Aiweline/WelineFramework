<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 结账收货地址槽：只走 required injection，禁止 soft fallback 直渲，槽须 exclusive。
 */
final class CheckoutShippingAddressSlotContractTest extends TestCase
{
    public function testIndexAndExpressReviewDeclareExclusiveShippingSlotWithoutSoftFallback(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml');
        $express = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml');

        foreach ([$index, $express] as $src) {
            self::assertStringContainsString('id="checkout-shipping-address"', $src);
            self::assertStringContainsString('exclusive="true"', $src);
            self::assertStringNotContainsString(
                "fetch('Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml')",
                $src,
            );
            self::assertStringNotContainsString('Soft fallback', $src);
            self::assertStringNotContainsString('SlotRenderer replaces this', $src);
        }

        // Shipping slot block must declare exclusive (between open tag id and its close).
        self::assertTrue($this->slotDeclaresExclusive($index, 'checkout-shipping-address'));
        self::assertTrue($this->slotDeclaresExclusive($express, 'checkout-shipping-address'));
        self::assertTrue($this->slotDeclaresExclusive($index, 'checkout-tax-identity'));
        self::assertTrue($this->slotDeclaresExclusive($express, 'checkout-tax-identity'));
    }

    private function slotDeclaresExclusive(string $src, string $slotId): bool
    {
        $needle = 'id="' . $slotId . '"';
        $pos = strpos($src, $needle);
        if ($pos === false) {
            return false;
        }
        $chunk = substr($src, $pos, 800);
        $end = strpos($chunk, '</w:slot>');
        if ($end === false) {
            $end = strpos($chunk, '/>');
        }
        if ($end !== false) {
            $chunk = substr($chunk, 0, $end);
        }

        return str_contains($chunk, 'exclusive="true"');
    }
}
