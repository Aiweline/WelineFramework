<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;

final class ContinuePaymentRecoveryUrlBuilderTest extends TestCase
{
    public function testBuildsRecoveryHashForFailedPayment(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_pay_1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'idem-1',
            'submitted_result' => [
                'order_uuids' => ['ord-1'],
                'checkout_group_uuid' => 'grp-1',
            ],
            'payment_result' => [
                'outcome' => 'pending',
                'transactions' => [['method_code' => 'paypal']],
            ],
        ]);

        $builder = new ContinuePaymentRecoveryUrlBuilder($store, 'https://shop.example.test');
        $result = $builder->build('qt_pay_1', 'ord-1');
        self::assertTrue($result['reachable']);
        self::assertStringContainsString('https://shop.example.test/checkout#payment-recovery?', $result['continue_pay_url']);
        self::assertStringContainsString('quote_token=qt_pay_1', $result['continue_pay_url']);
        self::assertStringContainsString('idempotency_key=idem-1', $result['continue_pay_url']);
        self::assertStringContainsString('payment_method=paypal', $result['continue_pay_url']);
        self::assertStringContainsString('order_uuid=ord-1', $result['continue_pay_url']);
        self::assertStringContainsString('recoverable=1', $result['continue_pay_url']);
    }

    public function testAppendsWlsHttpsPortForLocalTestHostWithoutOverride(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_port', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'idem-port',
            'submitted_result' => [
                'order_uuids' => ['ord-port'],
                'checkout_group_uuid' => 'grp-port',
            ],
            'payment_result' => [
                'outcome' => 'failed',
                'transactions' => [['method_code' => 'paypal']],
            ],
        ]);

        $prevHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'p05113ef3.test.weline.com:9555';
        try {
            $builder = new ContinuePaymentRecoveryUrlBuilder(
                $store,
                'https://p05113ef3.test.weline.com'
            );
            $result = $builder->build('qt_port', 'ord-port');
        } finally {
            if ($prevHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $prevHost;
            }
        }

        self::assertTrue($result['reachable']);
        self::assertStringContainsString(
            'https://p05113ef3.test.weline.com:9555/checkout#payment-recovery?',
            $result['continue_pay_url']
        );
    }

    public function testPaidOutcomeIsNotReachable(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_paid', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'idem-paid',
            'submitted_result' => ['order_uuids' => ['ord-paid']],
            'payment_result' => [
                'outcome' => 'paid',
                'transactions' => [['method_code' => 'paypal']],
            ],
        ]);
        $builder = new ContinuePaymentRecoveryUrlBuilder($store, 'https://shop.example.test');
        $result = $builder->build('qt_paid', 'ord-paid');
        self::assertFalse($result['reachable']);
        self::assertSame('', $result['continue_pay_url']);
    }
}
