<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;

final class CheckoutPaymentRecoveryStateServiceTest extends TestCase
{
    public function testOnlyFailedRecordedPaymentCanStartAnotherAttempt(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_recovery_1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-1',
            'submitted_result' => [
                'checkout_group_uuid' => 'group-1',
                'order_uuids' => ['order-1'],
            ],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        $service->record('qt_recovery_1', 'order-idem-1', [
            'paid' => false,
            'outcome' => 'pending',
            'status' => 'pending',
        ]);
        self::assertFalse($service->canRetry('qt_recovery_1', 'order-idem-1'));

        $service->record('qt_recovery_1', 'order-idem-1', [
            'paid' => false,
            'outcome' => 'failed',
            'status' => 'failed',
        ]);
        self::assertTrue($service->canRetry('qt_recovery_1', 'order-idem-1'));
        self::assertSame('failed', $service->get('qt_recovery_1', 'order-idem-1')['outcome']);
    }

    public function testWrongOrderIdempotencyKeyCannotReadOrOverwritePaymentState(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_recovery_2', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-2',
            'submitted_result' => ['order_uuids' => ['order-2']],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        self::assertNull($service->get('qt_recovery_2', 'wrong-key'));
        $this->expectExceptionMessage('checkout_payment_recovery_session_conflict');
        $service->record('qt_recovery_2', 'wrong-key', ['outcome' => 'failed']);
    }

    public function testBeginRetryClaimsFailedPaymentBeforeAnotherAttemptCanStart(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_recovery_3', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-3',
            'submitted_result' => ['order_uuids' => ['order-3']],
            'payment_result' => [
                'paid' => false,
                'outcome' => 'failed',
                'status' => 'failed',
                'recoverable' => true,
            ],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        self::assertTrue($service->beginRetry('qt_recovery_3', 'order-idem-3', 'payment-idem-3'));
        self::assertFalse($service->beginRetry('qt_recovery_3', 'order-idem-3', 'payment-idem-4'));

        $claimed = $service->get('qt_recovery_3', 'order-idem-3');
        self::assertSame('pending', $claimed['outcome']);
        self::assertFalse($claimed['recoverable']);
        self::assertSame('retry_in_progress', $claimed['status']);
    }

    public function testRecordAppendsPreviousPaymentResultToHistory(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_recovery_history_1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-history-1',
            'submitted_result' => ['order_uuids' => ['order-history-1']],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        $service->record('qt_recovery_history_1', 'order-idem-history-1', [
            'paid' => false,
            'outcome' => 'failed',
            'status' => 'failed',
            'transactions' => [['id' => 'txn-failed-1']],
            'recorded_at' => '2026-03-21T10:00:00+00:00',
            'recoverable' => true,
        ]);
        self::assertSame([], $service->history('qt_recovery_history_1', 'order-idem-history-1'));

        $service->record('qt_recovery_history_1', 'order-idem-history-1', [
            'paid' => false,
            'outcome' => 'pending',
            'status' => 'pending',
            'transactions' => [['id' => 'txn-pending-2']],
        ]);

        $history = $service->history('qt_recovery_history_1', 'order-idem-history-1');
        self::assertCount(1, $history);
        self::assertSame('failed', $history[0]['outcome']);
        self::assertSame('failed', $history[0]['status']);
        self::assertSame([['id' => 'txn-failed-1']], $history[0]['transactions']);
        self::assertSame('2026-03-21T10:00:00+00:00', $history[0]['recorded_at']);
        self::assertSame('pending', $service->get('qt_recovery_history_1', 'order-idem-history-1')['outcome']);
        self::assertFalse($service->canRetry('qt_recovery_history_1', 'order-idem-history-1'));

        $service->record('qt_recovery_history_1', 'order-idem-history-1', [
            'paid' => false,
            'outcome' => 'failed',
            'status' => 'failed',
            'recoverable' => true,
        ]);
        $history = $service->history('qt_recovery_history_1', 'order-idem-history-1');
        self::assertCount(2, $history);
        self::assertSame('failed', $history[0]['outcome']);
        self::assertSame('pending', $history[1]['outcome']);
        self::assertSame('pending', $history[1]['status']);
        self::assertTrue($service->canRetry('qt_recovery_history_1', 'order-idem-history-1'));
        self::assertSame('failed', $service->get('qt_recovery_history_1', 'order-idem-history-1')['outcome']);
    }

    public function testBeginRetryAppendsFailedPaymentResultToHistory(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_recovery_history_2', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-history-2',
            'submitted_result' => ['order_uuids' => ['order-history-2']],
            'payment_result' => [
                'paid' => false,
                'outcome' => 'failed',
                'status' => 'failed',
                'recoverable' => true,
                'transactions' => [['id' => 'txn-failed-retry']],
                'recorded_at' => '2026-03-21T11:00:00+00:00',
            ],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        self::assertTrue($service->beginRetry(
            'qt_recovery_history_2',
            'order-idem-history-2',
            'payment-idem-history-2'
        ));

        $history = $service->history('qt_recovery_history_2', 'order-idem-history-2');
        self::assertCount(1, $history);
        self::assertSame('failed', $history[0]['outcome']);
        self::assertSame('failed', $history[0]['status']);
        self::assertSame([['id' => 'txn-failed-retry']], $history[0]['transactions']);
        self::assertSame('2026-03-21T11:00:00+00:00', $history[0]['recorded_at']);

        $claimed = $service->get('qt_recovery_history_2', 'order-idem-history-2');
        self::assertSame('pending', $claimed['outcome']);
        self::assertSame('retry_in_progress', $claimed['status']);
        self::assertFalse($service->canRetry('qt_recovery_history_2', 'order-idem-history-2'));
    }

    public function testMarkBrowserCancelFlipsPendingToFailedRecoverable(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_cancel_1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-cancel',
            'submitted_result' => ['order_uuids' => ['order-cancel']],
            'payment_result' => [
                'paid' => false,
                'outcome' => 'pending',
                'status' => 'pending',
                'recoverable' => false,
                'transactions' => [['method_code' => 'paypal']],
            ],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        self::assertFalse($service->canRetry('qt_cancel_1', 'order-idem-cancel'));
        self::assertTrue($service->markBrowserCancel('qt_cancel_1'));
        self::assertTrue($service->canRetry('qt_cancel_1', 'order-idem-cancel'));
        $payment = $service->get('qt_cancel_1', 'order-idem-cancel');
        self::assertSame('failed', $payment['outcome']);
        self::assertSame('cancelled', $payment['status']);
        $history = $service->history('qt_cancel_1', 'order-idem-cancel');
        self::assertNotEmpty($history);
        self::assertSame('pending', $history[0]['outcome'] ?? null);
    }

    public function testInvalidatePendingIfAmountDriftedForcesRetry(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_drift_1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-drift',
            'submitted_result' => ['order_uuids' => ['order-drift']],
            'payment_result' => [
                'paid' => false,
                'outcome' => 'pending',
                'status' => 'pending',
                'recoverable' => false,
                'amount_minor' => 1669,
                'redirect_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=stale',
            ],
        ]);
        $service = new CheckoutPaymentRecoveryStateService($store);

        self::assertFalse($service->canRetry('qt_drift_1', 'order-idem-drift'));
        self::assertTrue($service->invalidatePendingIfAmountDrifted(
            'qt_drift_1',
            'order-idem-drift',
            1590,
            ['order-drift'],
        ));
        self::assertTrue($service->canRetry('qt_drift_1', 'order-idem-drift'));
        $payment = $service->get('qt_drift_1', 'order-idem-drift');
        self::assertSame('failed', $payment['outcome']);
        self::assertSame('amount_drift', $payment['cancel_source'] ?? null);
        self::assertSame(1669, (int)($payment['stale_amount_minor'] ?? 0));
        self::assertSame(1590, (int)($payment['authority_amount_minor'] ?? 0));

        // Same amount → no invalidate.
        $service->record('qt_drift_1', 'order-idem-drift', [
            'paid' => false,
            'outcome' => 'pending',
            'status' => 'pending',
            'amount_minor' => 1590,
        ]);
        self::assertFalse($service->invalidatePendingIfAmountDrifted(
            'qt_drift_1',
            'order-idem-drift',
            1590,
            ['order-drift'],
        ));
    }
}
