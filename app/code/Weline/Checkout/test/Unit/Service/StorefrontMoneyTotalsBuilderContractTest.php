<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contract: single money channel + discount extensibility (coupon via cart preview).
 */
final class StorefrontMoneyTotalsBuilderContractTest extends TestCase
{
    private function builderSrc(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontMoneyTotalsBuilder.php'
        );
    }

    public function testBuilderExistsAndOwnsPayableFormula(): void
    {
        $src = $this->builderSrc();
        self::assertStringContainsString('final class StorefrontMoneyTotalsBuilder', $src);
        self::assertStringContainsString('function build', $src);
        self::assertStringContainsString('function mergeIntoPayload', $src);
        self::assertStringContainsString('payment_incentive_minor', $src);
        self::assertStringContainsString('discount_minor', $src);
        self::assertStringContainsString('discount_preview', $src);
        self::assertStringContainsString('payable_minor', $src);
        self::assertStringContainsString('adjustDto', $src);
        // Cart baseline must not be overwritten with payable (channel semantics).
        self::assertStringContainsString('Do not overwrite cart.grand_total with payable', $src);
    }

    public function testBuilderArithmeticLocksIncentiveAndCouponTogether(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/StorefrontMoneyTotalsBuilder.php';
        $builder = new \Weline\Checkout\Service\StorefrontMoneyTotalsBuilder();
        $dto = $builder->build([
            'currency' => 'USD',
            'cart' => [
                'subtotal' => 320.35,
                'subtotal_minor' => 32035,
                'cart_type' => 'toc',
                'discount_preview' => [
                    'amount' => 10.0,
                    'amount_minor' => 1000,
                    'coupon_code' => 'DEMO10',
                ],
            ],
            'shipping_methods' => [[
                'code' => 'STD',
                'amount' => 241.82,
                'amount_minor' => 24182,
            ]],
            'payment_methods' => [[
                'code' => 'fake_card',
                'incentive_available' => true,
                'incentive_savings_minor' => 500,
                'cod_fee_amount_minor' => 0,
            ]],
            'tax_estimate' => [
                'sales_tax_amount_minor' => 0,
                'duty_amount_minor' => 4497,
                'import_tax_amount_minor' => 0,
            ],
            'selected_payment_method' => 'fake_card',
            'selected_shipping_method' => 'STD',
            'cart_type' => 'toc',
        ]);

        // 32035 + 24182 - 1000 + 4497 + 0 - 500 = 59214
        self::assertSame(1000, (int)$dto['discount_minor']);
        self::assertSame(500, (int)$dto['payment_incentive_minor']);
        self::assertSame(4497, (int)$dto['duty_minor']);
        self::assertSame(59214, (int)$dto['payable_minor']);
        self::assertNotSame('', (string)$dto['incentive_text']);
        self::assertStringContainsString('-', (string)$dto['incentive_text']);
    }

    public function testSelectedPaymentChangesIncentiveOnSameChannel(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/StorefrontMoneyTotalsBuilder.php';
        $builder = new \Weline\Checkout\Service\StorefrontMoneyTotalsBuilder();
        $base = [
            'currency' => 'USD',
            'cart' => ['subtotal_minor' => 10000, 'cart_type' => 'toc'],
            'shipping_methods' => [['code' => 'STD', 'amount_minor' => 0]],
            'payment_methods' => [
                [
                    'code' => 'fake_card',
                    'incentive_available' => true,
                    'incentive_savings_minor' => 500,
                ],
                [
                    'code' => 'paypal',
                    'incentive_available' => false,
                    'incentive_savings_minor' => 0,
                ],
            ],
            'tax_estimate' => [],
            'cart_type' => 'toc',
        ];
        $withIncentive = $builder->build($base + ['selected_payment_method' => 'fake_card']);
        $without = $builder->build($base + ['selected_payment_method' => 'paypal']);
        self::assertSame(500, (int)$withIncentive['payment_incentive_minor']);
        self::assertSame(9500, (int)$withIncentive['payable_minor']);
        self::assertSame(0, (int)$without['payment_incentive_minor']);
        self::assertSame(10000, (int)$without['payable_minor']);
    }
}
