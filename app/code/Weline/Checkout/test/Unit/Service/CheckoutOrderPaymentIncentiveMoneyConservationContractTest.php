<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\MoneySnapshot;

/**
 * Contract: payment-incentive money sync must persist a conserved MoneySnapshot
 * (subtotal+shipping+tax+cod−discount === grand) so OrderPaidContext / mail can run.
 */
final class CheckoutOrderPaymentIncentiveMoneyConservationContractTest extends TestCase
{
    public function test_sync_source_uses_with_computed_grand_total_not_gateway_amount(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutOrderPaymentService.php',
        );
        self::assertStringContainsString('withComputedGrandTotal', $src);
        self::assertStringContainsString('ensureOrderMoneyConserved', $src);
        self::assertStringContainsString('logOrderPaidNotificationFailure', $src);
        self::assertStringContainsString('notification_retry', $src);
        // Must not assign gateway amount_minor directly as snapshot grand.
        self::assertDoesNotMatchRegularExpression(
            '/money\[\'grand_total_minor\'\]\s*=\s*\$newGrand/',
            $src,
        );
    }

    public function test_taxed_incentive_money_conserves_via_money_snapshot(): void
    {
        // Reproduces UC-4 class of failure: UI payable 193.00 with tax still 15.84.
        $brokenGrand = 19300;
        $components = [
            'currency' => 'CNY',
            'subtotal_minor' => 19800,
            'shipping_amount_minor' => 0,
            'tax_amount_minor' => 1584,
            'discount_amount_minor' => 500,
            'grand_total_minor' => $brokenGrand,
        ];
        $broken = MoneySnapshot::fromArray($components);
        self::assertNotSame(
            $broken->subtotalMinor + $broken->shippingAmountMinor + $broken->taxAmountMinor
            - $broken->discountAmountMinor + $broken->codFeeAmountMinor,
            $broken->grandTotalMinor,
        );

        $fixed = $broken->withComputedGrandTotal();
        self::assertSame(20884, $fixed->grandTotalMinor);
        self::assertSame(
            $fixed->subtotalMinor + $fixed->shippingAmountMinor + $fixed->taxAmountMinor
            - $fixed->discountAmountMinor + $fixed->codFeeAmountMinor,
            $fixed->grandTotalMinor,
        );
    }
}
