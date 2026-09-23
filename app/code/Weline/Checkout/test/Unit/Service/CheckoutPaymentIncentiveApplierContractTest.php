<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;

final class CheckoutPaymentIncentiveApplierContractTest extends TestCase
{
    public function test_apply_merges_payment_method_incentive_into_discount_lines_and_reduces_grand(): void
    {
        $svc = new PaymentMethodIncentiveQuoteService();
        $runtime = [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
            'incentive_funding_source' => 'merchant',
            'incentive_publish_version' => 'parity-v1',
        ];
        $applied = $svc->applyToOrderData('fake_card', [
            'amount_minor' => 1892,
            'currency' => 'USD',
            'discount_lines' => [],
        ], $runtime);

        self::assertSame(500, (int)$applied['savings_minor']);
        self::assertSame(1392, (int)$applied['order_data']['amount_minor']);
        $sources = array_map(
            static fn (array $line): string => (string)($line['source_type'] ?? ''),
            $applied['order_data']['discount_lines'],
        );
        self::assertContains(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $sources);
        self::assertSame(-500, (int)$applied['order_data']['payment_method_incentive_amount_minor']);
    }

    public function test_apply_is_idempotent_when_discount_lines_already_contain_incentive(): void
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

        self::assertSame(1392, (int)$second['order_data']['amount_minor']);
        self::assertSame(500, (int)$second['savings_minor']);
        self::assertCount(1, $second['order_data']['discount_lines']);
    }

    public function test_checkout_applier_and_submit_wiring_sources_exist(): void
    {
        $applier = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutPaymentIncentiveApplier.php',
        );
        self::assertStringContainsString('PaymentMethodIncentiveQuoteInterface', $applier);
        self::assertStringContainsString('applyToPaymentContext', $applier);

        $submit = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php',
        );
        self::assertStringContainsString('CheckoutPaymentIncentiveApplier', $submit);
        self::assertStringContainsString('discount_lines', $submit);
        self::assertStringContainsString('payment_method_incentive_amount_minor', $submit);

        $pay = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutOrderPaymentService.php',
        );
        self::assertStringContainsString('CheckoutPaymentIncentiveApplier', $pay);
        self::assertStringContainsString('syncOrderPaymentIncentive', $pay);
    }
}
