<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\RefundCase;
use Weline\Order\Service\OrderRefundCoordinator;
use Weline\Payment\Model\PaymentRefund;

/**
 * TEST-REFUND-01, TEST-REFUND-02, TEST-REFUND-03, TEST-REFUND-04,
 * TEST-REFUND-05 and TEST-REFUND-06（memory harness）.
 */
final class OrderRefundCoordinatorTest extends TestCase
{
    private OrderRefundCoordinator $coord;

    protected function setUp(): void
    {
        $this->coord = OrderRefundCoordinator::forTesting();
        $this->coord->seedPaidOrder('ord-r1', 10000, [
            ['item_uuid' => 'i1', 'qty_minor' => 2, 'unit_price_minor' => 4000, 'shipped' => false],
            ['item_uuid' => 'i2', 'qty_minor' => 1, 'unit_price_minor' => 2000, 'shipped' => true],
        ], shippingFrozenMinor: 0);
    }

    public function testRefund01ConcurrentKeysCannotExceedCaptured(): void
    {
        $a = $this->coord->requestRefund('ord-r1', 'key-a', 7000);
        $b = $this->coord->requestRefund('ord-r1', 'key-b', 4000);
        self::assertTrue($a['ok']);
        self::assertFalse($b['ok']);
        self::assertSame(OrderRefundCoordinator::ERROR_AMOUNT_EXCEEDS, $b['error_code']);
        self::assertSame(7000, $this->coord->occupiedAmount('ord-r1'));
        self::assertSame(3000, $this->coord->remainingAmount('ord-r1'));
    }

    public function testRefund02PendingUnknownShowProcessingToCustomer(): void
    {
        $r = $this->coord->requestRefund('ord-r1', 'key-p', 1000);
        $uuid = $r['case']['refund_case_uuid'];
        $this->coord->applyChannelResult($uuid, 'accepted');
        self::assertSame(OrderRefundCoordinator::CUSTOMER_VIEW_PROCESSING, $this->coord->customerView($uuid));
        $this->coord->applyChannelResult($uuid, 'unknown');
        self::assertSame(PaymentRefund::CHANNEL_UNKNOWN, $this->coord->getPayment($uuid)['channel_status']);
        self::assertSame(OrderRefundCoordinator::CUSTOMER_VIEW_PROCESSING, $this->coord->customerView($uuid));
        self::assertSame(1000, $this->coord->occupiedAmount('ord-r1'));
        $this->coord->applyChannelResult($uuid, 'succeeded', 'pref-1');
        self::assertSame(OrderRefundCoordinator::CUSTOMER_VIEW_SUCCEEDED, $this->coord->customerView($uuid));
    }

    public function testRefund03CashSucceededPostStepsRetryAtMostOnce(): void
    {
        $r = $this->coord->requestRefund('ord-r1', 'key-c', 500);
        $uuid = $r['case']['refund_case_uuid'];
        $this->coord->applyChannelResult($uuid, 'succeeded', 'pref-c');
        self::assertSame(PaymentRefund::STATUS_REFUNDED, $this->coord->getPayment($uuid)['status']);
        // Steps already done by apply — retry returns false (at most once).
        self::assertFalse($this->coord->retryPostCashStep($uuid, 'inventory:restock:v1'));
        self::assertSame(PaymentRefund::STATUS_REFUNDED, $this->coord->getPayment($uuid)['status']);
    }

    public function testRefund04ShippedLinesDoNotRestock(): void
    {
        $r = $this->coord->requestRefund('ord-r1', 'key-ship', 0, [
            ['item_uuid' => 'i2', 'qty_minor' => 1],
        ]);
        self::assertTrue($r['ok']);
        self::assertFalse($r['case']['items'][0]['restock']);
        self::assertTrue($r['case']['items'][0]['shipped']);
        self::assertSame(2000, $r['case']['amount_minor']);
    }

    public function testRefund05UnknownOccupiesThenBlocksConcurrent(): void
    {
        $a = $this->coord->requestRefund('ord-r1', 'key-a5', 7000);
        $uuid = $a['case']['refund_case_uuid'];
        $this->coord->applyChannelResult($uuid, 'timeout');
        self::assertSame(PaymentRefund::STATUS_UNKNOWN, $this->coord->getPayment($uuid)['status']);
        $b = $this->coord->requestRefund('ord-r1', 'key-b5', 4000);
        self::assertFalse($b['ok']);
        self::assertSame(OrderRefundCoordinator::ERROR_AMOUNT_EXCEEDS, $b['error_code']);
        $this->coord->applyChannelResult($uuid, 'succeeded', 'pref-a5');
        self::assertSame(PaymentRefund::CHANNEL_SUCCEEDED, $this->coord->getPayment($uuid)['channel_status']);
        self::assertLessThanOrEqual(10000, $this->coord->occupiedAmount('ord-r1'));
    }

    public function testRefund06LateSuccessAfterFailedEntersReviewAndFreezes(): void
    {
        $a = $this->coord->requestRefund('ord-r1', 'key-a6', 4000);
        $uuid = $a['case']['refund_case_uuid'];
        $this->coord->applyChannelResult($uuid, 'failed');
        self::assertSame(0, $this->coord->occupiedAmount('ord-r1'));

        $b = $this->coord->requestRefund('ord-r1', 'key-b6', 4000);
        self::assertTrue($b['ok']);
        $this->coord->applyChannelResult($b['case']['refund_case_uuid'], 'succeeded', 'pref-b');

        $late = $this->coord->applyChannelResult($uuid, 'succeeded', 'pref-late');
        self::assertSame(RefundCase::STATUS_LATE_SUCCESS_REVIEW, $late['case']['status']);
        self::assertSame(PaymentRefund::STATUS_LATE_SUCCESS_REVIEW, $late['payment']['status']);
        self::assertTrue($this->coord->isOrderFrozen('ord-r1'));
        self::assertCount(1, $this->coord->urgentEvents());
        self::assertSame('external_observed_late_success', $this->coord->ledger()[0]['type']);

        $blocked = $this->coord->requestRefund('ord-r1', 'key-c6', 1000);
        self::assertSame(OrderRefundCoordinator::ERROR_ORDER_FROZEN, $blocked['error_code']);
    }

    public function testNewRefundsCanBeDisabled(): void
    {
        $this->coord->setNewRefundsEnabled(false);
        $r = $this->coord->requestRefund('ord-r1', 'key-off', 100);
        self::assertSame(OrderRefundCoordinator::ERROR_NEW_DISABLED, $r['error_code']);
    }
}
