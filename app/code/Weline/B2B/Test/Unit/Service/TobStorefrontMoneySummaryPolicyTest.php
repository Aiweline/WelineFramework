<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\TobStorefrontMoneySummaryPolicy;
use Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface;

final class TobStorefrontMoneySummaryPolicyTest extends TestCase
{
    public function testImplementsCheckoutMoneySummaryPolicyInterface(): void
    {
        $policy = new TobStorefrontMoneySummaryPolicy();
        self::assertInstanceOf(StorefrontMoneySummaryPolicyInterface::class, $policy);
    }

    public function testAdjustSsrPayloadLeavesRetailUntouched(): void
    {
        $policy = new TobStorefrontMoneySummaryPolicy();
        $payload = [
            'cart_type' => 'toc',
            'cart' => ['grand_total' => 100.0, 'cart_type' => 'toc'],
            'payable_text' => '$100.00',
            'payment_methods' => [[
                'code' => 'fake_card',
                'incentive_available' => true,
                'incentive_savings_minor' => 500,
                'incentive_display' => '减 $5',
            ]],
            'payment_methods_html' => '<span data-payment-incentive>减 $5</span>',
        ];
        $out = $policy->adjustSsrPayload($payload);
        self::assertSame($payload, $out);
    }

    public function testAdjustSsrPayloadClearsIncentiveWithoutRewritingCartGrandForTob(): void
    {
        $policy = new TobStorefrontMoneySummaryPolicy();
        $payload = [
            'cart_type' => 'tob',
            'currency' => 'USD',
            'cart' => ['grand_total' => 109.45, 'cart_type' => 'tob'],
            'payable_text' => '$109.45',
            'incentive_text' => '-$5.00',
            'incentive_minor' => 500,
            'money_summary' => [
                'payment_incentive_minor' => 500,
                'incentive_minor' => 500,
                'incentive_text' => '-$5.00',
                'payable_text' => '$104.45',
                'payable_minor' => 10445,
            ],
            'payment_methods' => [[
                'code' => 'fake_card',
                'incentive_available' => true,
                'incentive_savings_minor' => 500,
                'incentive_display' => '减 $5.00',
            ]],
            'payment_methods_html' => '<label class="weline-checkout__option--payment weline-checkout__option--has-incentive">'
                . '<span class="weline-checkout__payment-incentive" data-payment-incentive'
                . ' data-incentive-savings-minor="500">减 $5.00</span></label>',
        ];
        $out = $policy->adjustSsrPayload($payload);
        // Cart baseline unchanged — Builder owns payable; no add-back into grand_total.
        self::assertSame(109.45, (float)$out['cart']['grand_total']);
        self::assertFalse((bool)$out['payment_methods'][0]['incentive_available']);
        self::assertSame(0, (int)$out['payment_methods'][0]['incentive_savings_minor']);
        self::assertSame('', (string)$out['incentive_text']);
        self::assertSame(0, (int)$out['incentive_minor']);
        self::assertSame(0, (int)$out['money_summary']['payment_incentive_minor']);
        self::assertStringNotContainsString('data-payment-incentive', (string)$out['payment_methods_html']);
        self::assertStringNotContainsString('has-incentive', (string)$out['payment_methods_html']);
        self::assertStringContainsString('到港由买家另付', (string)$out['money_summary_note']);
    }

    public function testAdjustDtoZerosPromoAndTaxForTob(): void
    {
        $policy = new TobStorefrontMoneySummaryPolicy();
        $dto = [
            'cart_type' => 'tob',
            'goods_subtotal_minor' => 39485,
            'shipping_minor' => 12477,
            'discount_minor' => 100,
            'payment_incentive_minor' => 500,
            'sales_tax_minor' => 200,
            'customs_duty_minor' => 300,
            'import_tax_minor' => 400,
            'cod_fee_minor' => 50,
            'payable_minor' => 9999,
        ];
        $out = $policy->adjustDto($dto, [
            'cart_type' => 'tob',
            'deposit_minor' => 11845,
            'credit_minor' => 900,
            'payable_minor' => 10945,
            'payable_label_deposit' => '本次应付定金',
        ]);
        self::assertTrue($out['commerce_deposit_allowed']);
        self::assertSame(0, (int)$out['discount_minor']);
        self::assertSame(0, (int)$out['payment_incentive_minor']);
        self::assertSame(0, (int)$out['sales_tax_minor']);
        self::assertSame(0, (int)$out['tax_minor']);
        self::assertSame(11845, (int)$out['deposit_minor']);
        self::assertSame(900, (int)$out['credit_minor']);
        self::assertSame(10945, (int)$out['payable_minor']);
        self::assertSame(51962, (int)$out['order_total_minor']);
        self::assertSame('本单共计', $out['order_total_label']);
        self::assertSame('本次应付定金', $out['payable_label']);
        self::assertStringContainsString('到港由买家另付', (string)$out['note']);
    }

    public function testAdjustDtoShellTocWinsOverStickyTobDto(): void
    {
        $policy = new TobStorefrontMoneySummaryPolicy();
        $dto = [
            'cart_type' => 'tob',
            'commerce_deposit_allowed' => true,
            'deposit_minor' => 11845,
            'credit_minor' => 900,
            'order_total_minor' => 51962,
            'order_total_label' => '本单共计',
            'payable_label' => '本次应付定金',
            'payable_minor' => 10945,
            'goods_subtotal_minor' => 39485,
        ];
        $out = $policy->adjustDto($dto, [
            'shell_cart_type' => 'toc',
            'cart_type' => 'tob',
            'payable_label_retail' => '小计',
        ]);
        self::assertSame('toc', $out['cart_type']);
        self::assertFalse((bool)$out['commerce_deposit_allowed']);
        self::assertSame(0, (int)$out['deposit_minor']);
        self::assertSame(0, (int)$out['credit_minor']);
        self::assertSame(0, (int)$out['order_total_minor']);
        self::assertSame('', (string)$out['order_total_label']);
        self::assertSame('小计', $out['payable_label']);
    }
}
