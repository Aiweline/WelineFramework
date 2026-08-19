<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PayableResolverRegistry;
use Weline\Payment\Service\PaymentTransactionPayableStateInvariant;

final class PaymentTransactionPayableStateInvariantTest extends TestCase
{
    public function testSuccessfulTransactionReportsOpenPayableAndIgnoresPaidPayable(): void
    {
        self::assertTrue(
            class_exists(PaymentTransactionPayableStateInvariant::class),
            'The transaction/payable reconciliation invariant must exist.',
        );

        $registry = new PayableResolverRegistry();
        $registry->register(OrderPayableResolver::forTesting([
            'order-open' => [
                'status' => 'pending',
                'payment_status' => 'pending',
            ],
            'order-paid' => [
                'status' => 'paid',
                'payment_status' => 'paid',
            ],
        ]));
        $invariant = new PaymentTransactionPayableStateInvariant($registry);

        $diff = $invariant->inspect($this->transaction('order-open'));

        self::assertIsArray($diff);
        self::assertSame('successful_transaction_payable_not_paid', $diff['code']);
        self::assertSame('payable_not_paid', $diff['reason']);
        self::assertSame('weline_order', $diff['payable_type']);
        self::assertSame('order-open', $diff['payable_id']);
        self::assertSame('open', $diff['payable_status']);
        self::assertSame('pending', $diff['payment_status']);
        self::assertNull($invariant->inspect($this->transaction('order-paid')));
    }

    public function testSuccessfulTransactionReportsUnresolvablePayableButNeverRepairsIt(): void
    {
        self::assertTrue(class_exists(PaymentTransactionPayableStateInvariant::class));

        $registry = new PayableResolverRegistry();
        $registry->register(OrderPayableResolver::forTesting());
        $invariant = new PaymentTransactionPayableStateInvariant($registry);

        $diff = $invariant->inspect($this->transaction('order-missing'));

        self::assertIsArray($diff);
        self::assertSame('successful_transaction_payable_not_paid', $diff['code']);
        self::assertSame('payable_unresolved', $diff['reason']);
        self::assertSame('order-missing', $diff['payable_id']);
        self::assertArrayHasKey('resolution_error', $diff);
    }

    public function testNonSuccessfulTransactionIsOutsideThisInvariant(): void
    {
        self::assertTrue(class_exists(PaymentTransactionPayableStateInvariant::class));

        $registry = new PayableResolverRegistry();
        $registry->register(OrderPayableResolver::forTesting([
            'order-open' => [
                'status' => 'pending',
                'payment_status' => 'pending',
            ],
        ]));
        $invariant = new PaymentTransactionPayableStateInvariant($registry);

        $transaction = $this->transaction('order-open');
        $transaction[PaymentTransaction::schema_fields_STATUS] = PaymentTransaction::STATUS_PENDING;

        self::assertNull($invariant->inspect($transaction));
    }

    public function testPayableWithoutAuthoritativePaymentStatusIsNotMisreported(): void
    {
        $invariant = new PaymentTransactionPayableStateInvariant(new PayableResolverRegistry());
        $transaction = $this->transaction('generic-payable');
        $transaction[PaymentTransaction::schema_fields_REQUEST_DATA] = json_encode([
            'payable_type' => PayableResolverRegistry::DEFAULT_PAYABLE_TYPE,
            'payable_id' => 'generic-payable',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertNull($invariant->inspect($transaction));
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(string $orderId): array
    {
        return [
            PaymentTransaction::schema_fields_ID => 7,
            PaymentTransaction::schema_fields_TRANSACTION_NO => 'PAY-TEST-7',
            PaymentTransaction::schema_fields_ORDER_ID => $orderId,
            PaymentTransaction::schema_fields_STATUS => PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::schema_fields_SCOPE => 'default.default.default',
            PaymentTransaction::schema_fields_REQUEST_DATA => json_encode([
                'payable_type' => 'weline_order',
                'payable_id' => $orderId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
