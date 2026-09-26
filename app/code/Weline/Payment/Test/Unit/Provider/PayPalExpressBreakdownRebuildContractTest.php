<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\AmountBreakdownBuilder;

/**
 * Express confirm 重报价后，breakdown 必须按 totals 重建，不能只改 value 保留旧分量。
 */
final class PayPalExpressBreakdownRebuildContractTest extends TestCase
{
    public function testProviderRebuildsBreakdownFromTotalsWhenStaleValueDiffers(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php',
        );
        self::assertStringContainsString('有权威 totals 时一律按 totals 重建', $src);
        self::assertStringContainsString('$hasFreshTotals', $src);
        self::assertStringContainsString('$builder->fromOrderData($context + [', $src);
    }

    public function testFromOrderDataConservesDiscountedGrandTotal(): void
    {
        $builder = new AmountBreakdownBuilder();
        $breakdown = $builder->fromOrderData([
            'amount_minor' => 68636,
            'currency' => 'USD',
            'totals' => [
                'subtotal_minor' => 36505,
                'shipping_amount_minor' => 35782,
                'tax_amount_minor' => 0,
                'discount_amount_minor' => 3651,
                'grand_total_minor' => 68636,
            ],
            'discount_lines' => [[
                'key' => 'DEMO10',
                'label' => 'DEMO10',
                'amount_minor' => 3651,
                'source_type' => 'coupon',
            ]],
        ]);

        self::assertSame(68636, (int) $breakdown['value_minor']);
        self::assertSame(36505, (int) $breakdown['item_total_minor']);
        self::assertSame(35782, (int) $breakdown['shipping_minor']);
        self::assertSame(3651, (int) $breakdown['discount_minor']);
        $computed = (int) $breakdown['item_total_minor']
            + (int) $breakdown['tax_total_minor']
            + (int) $breakdown['shipping_minor']
            - (int) $breakdown['discount_minor'];
        self::assertSame(68636, $computed);
    }

    public function testScaleToValueDoesNotLeaveStaleComponentsWhenOnlyValueChanged(): void
    {
        $builder = new AmountBreakdownBuilder();
        // Stale create-time breakdown (wrong components, conserved to 45152)
        $stale = [
            'currency_code' => 'USD',
            'value_minor' => 45152,
            'item_total_minor' => 45152,
            'shipping_minor' => 12298,
            'handling_minor' => 0,
            'tax_total_minor' => 0,
            'insurance_minor' => 0,
            'shipping_discount_minor' => 0,
            'discount_minor' => 12298,
        ];
        $scaled = $builder->scaleToValue($stale, 68636, 'USD');
        $computed = (int) $scaled['item_total_minor']
            + (int) $scaled['tax_total_minor']
            + (int) $scaled['shipping_minor']
            + (int) $scaled['handling_minor']
            + (int) $scaled['insurance_minor']
            - (int) $scaled['shipping_discount_minor']
            - (int) $scaled['discount_minor'];
        self::assertSame(68636, (int) $scaled['value_minor']);
        self::assertSame(68636, $computed);
    }
}
