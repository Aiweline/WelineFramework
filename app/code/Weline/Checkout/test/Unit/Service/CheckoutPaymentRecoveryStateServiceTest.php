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
}
