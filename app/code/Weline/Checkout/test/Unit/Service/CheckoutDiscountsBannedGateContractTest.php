<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Checkout must not hardcode cart_type===tob for discounts_banned;
 * commerce type registry / OrderCalculatorGate owns the ban (B2B TobCommerceOrderType).
 */
final class CheckoutDiscountsBannedGateContractTest extends TestCase
{
    public function testDiscountsBannedUsesOrderCalculatorGateWithoutTobHardcode(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertStringContainsString('function discountsBannedForCartType', $src);
        self::assertStringContainsString('OrderCalculatorGate', $src);
        self::assertStringContainsString('allowsPriceChangingCalculators', $src);
        self::assertStringContainsString('defer_inventory still rides this flag', $src);
        self::assertStringNotContainsString("if (\$code === 'tob')", $src);
        self::assertStringNotContainsString('if ($code === "tob")', $src);
    }
}
