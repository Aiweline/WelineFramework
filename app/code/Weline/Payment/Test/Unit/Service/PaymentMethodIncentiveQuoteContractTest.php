<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;
use Weline\Payment\Service\AmountBreakdownBuilder;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;

final class PaymentMethodIncentiveQuoteContractTest extends TestCase
{
    public function testDisabledConfigYieldsZero(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $quote = $svc->quote('paypal', ['incentive_enabled' => 0], 10000, 'CNY', true);
        self::assertFalse($quote['available']);
        self::assertSame(0, $quote['savings_minor']);
    }

    public function testFixedAmountQuoteAndListPayload(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $quote = $svc->quote('paypal', [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
            'incentive_funding_source' => 'merchant',
            'incentive_publish_version' => 'v1',
        ], 10000, 'CNY', true);

        self::assertTrue($quote['available']);
        self::assertSame(500, $quote['savings_minor']);
        self::assertIsArray($quote['line']);
        self::assertSame(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $quote['line']['source_type']);
        self::assertSame(-500, $quote['line']['amount_minor']);
        self::assertSame('paypal', $quote['line']['method_code']);
        self::assertSame('pmi:paypal:v1', $quote['line']['key']);

        $fields = $svc->toListPayloadFields($quote);
        self::assertSame(500, $fields['incentive_savings_minor']);
        self::assertTrue($fields['incentive_available']);
        self::assertNotSame('', $fields['incentive_display']);
        self::assertStringNotContainsString('Save %1', $fields['incentive_display']);
        self::assertStringNotContainsString('%{1}', $fields['incentive_display']);
        self::assertStringContainsString('CNY', $fields['incentive_display']);
        self::assertSame('fixed_amount', $fields['incentive_type']);
    }

    public function testPercentageWithCap(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $quote = $svc->quote('fake_card', [
            'incentive_enabled' => true,
            'incentive_type' => 'percentage',
            'incentive_percent' => 10,
            'incentive_cap_minor' => 300,
        ], 10000, 'USD', true);
        self::assertSame(300, $quote['savings_minor']);
        self::assertSame(10.0, $quote['percent']);
    }

    public function testApplyToOrderDataMergesIncentiveLineAndLowersPayable(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $applied = $svc->applyToOrderData('paypal', [
            'amount_minor' => 10000,
            'currency' => 'CNY',
            'discount_lines' => [[
                'key' => 'coupon:SAVE10',
                'label' => '券',
                'amount_minor' => -1000,
                'source_type' => 'coupon',
            ]],
            'totals' => [
                'subtotal_minor' => 11000,
                'discount_amount_minor' => 1000,
                'grand_total_minor' => 10000,
                'shipping_amount_minor' => 0,
                'tax_amount_minor' => 0,
            ],
        ], [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
            'incentive_publish_version' => 'v2',
        ]);

        self::assertSame(500, $applied['savings_minor']);
        self::assertSame(9500, $applied['order_data']['amount_minor']);
        $sources = array_map(
            static fn (array $l): string => (string) ($l['source_type'] ?? ''),
            $applied['order_data']['discount_lines'],
        );
        self::assertContains('coupon', $sources);
        self::assertContains(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $sources);
        self::assertCount(2, $applied['order_data']['discount_lines']);
    }

    public function testApplyToOrderDataIsIdempotentWhenIncentiveLineAlreadyPresent(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $runtime = [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
            'incentive_publish_version' => 'parity-v1',
        ];
        $first = $svc->applyToOrderData('fake_card', [
            'amount_minor' => 1892,
            'currency' => 'USD',
        ], $runtime);
        $second = $svc->applyToOrderData('fake_card', $first['order_data'], $runtime);

        self::assertSame(1392, (int) $second['order_data']['amount_minor']);
        self::assertSame(500, (int) $second['savings_minor']);
        self::assertCount(1, $second['order_data']['discount_lines']);
    }

    public function testUnavailableMethodDoesNotAdvertiseIncentive(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $quote = $svc->quote('paypal', [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
        ], 10000, 'CNY', false);
        $fields = $svc->toListPayloadFields($quote);
        self::assertFalse($fields['incentive_available']);
        self::assertSame(0, $fields['incentive_savings_minor']);
    }
}

final class AmountBreakdownBuilderContractTest extends TestCase
{
    public function testBreakdownConservesValue(): void
    {
        $builder = new AmountBreakdownBuilder();
        $breakdown = $builder->fromSnapshot([
            'currency' => 'USD',
            'subtotal_minor' => 10000,
            'shipping_amount_minor' => 1000,
            'tax_amount_minor' => 500,
            'discount_amount_minor' => 1500,
            'grand_total_minor' => 10000,
        ], [
            ['key' => 'coupon:A', 'label' => '券', 'amount_minor' => -1000, 'source_type' => 'coupon'],
            ['key' => 'pmi:paypal:v1', 'label' => '激励', 'amount_minor' => -500, 'source_type' => 'payment_method_incentive'],
        ]);

        $computed = $breakdown['item_total_minor']
            + $breakdown['tax_total_minor']
            + $breakdown['shipping_minor']
            + $breakdown['handling_minor']
            + $breakdown['insurance_minor']
            - $breakdown['shipping_discount_minor']
            - $breakdown['discount_minor'];

        self::assertSame($breakdown['value_minor'], $computed);
        self::assertSame(1500, $breakdown['discount_minor']);
        self::assertSame(0, $breakdown['shipping_discount_minor']);
    }

    public function testShippingDiscountSeparated(): void
    {
        $builder = new AmountBreakdownBuilder();
        $breakdown = $builder->fromOrderData([
            'currency' => 'USD',
            'amount_minor' => 9000,
            'discount_lines' => [
                ['amount_minor' => -500, 'source_type' => 'shipping', 'label' => '包邮'],
                ['amount_minor' => -500, 'source_type' => 'payment_method_incentive', 'label' => '激励'],
            ],
            'totals' => [
                'subtotal_minor' => 10000,
                'shipping_amount_minor' => 1000,
                'tax_amount_minor' => 0,
                'grand_total_minor' => 9000,
            ],
        ]);
        self::assertSame(500, $breakdown['shipping_discount_minor']);
        self::assertSame(500, $breakdown['discount_minor']);
        $computed = $breakdown['item_total_minor']
            + $breakdown['tax_total_minor']
            + $breakdown['shipping_minor']
            - $breakdown['shipping_discount_minor']
            - $breakdown['discount_minor'];
        self::assertSame(9000, $computed);
    }
}
