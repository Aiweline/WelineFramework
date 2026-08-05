<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Service\ArrayCashAttemptPort;
use Weline\CustomerAsset\Service\CustomerAssetCheckoutOrchestrator;
use Weline\CustomerAsset\Service\CustomerAssetConflictException;
use Weline\CustomerAsset\Service\CustomerAssetRefundReturnService;
use Weline\CustomerAsset\Service\CustomerAssetService;

/**
 * TEST-P4D-02 / 03 / 04 / 05：现金编排、commit/return 重试、mode off 既有义务。
 */
final class CustomerAssetCheckoutOrchestratorTest extends TestCase
{
    private CustomerAssetService $assets;
    private ArrayCashAttemptPort $cash;
    private CustomerAssetCheckoutOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->assets = CustomerAssetService::forTesting();
        $this->assets->enableAllowlist(['website:0']);
        $this->assets->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_LIVE,
            'amount_minor' => 500,
            'event_id' => 'credit-seed',
        ]);
        $this->cash = ArrayCashAttemptPort::forTesting();
        $this->orchestrator = CustomerAssetCheckoutOrchestrator::forTesting($this->assets, $this->cash);
        $this->orchestrator->registerPayable([
            'payable_id' => 'pay-1',
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'total_minor' => 1000,
            'asset_amount_minor' => 500,
            'asset_code' => 'credit',
        ]);
    }

    public function testReserveFailureCreatesNoCashAttempt(): void
    {
        $this->orchestrator->registerPayable([
            'payable_id' => 'pay-poor',
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'total_minor' => 1000,
            'asset_amount_minor' => 9999,
        ]);
        $before = $this->cash->attemptCount();
        $result = $this->orchestrator->pay('pay-poor');
        self::assertFalse($result['ok']);
        self::assertSame(CustomerAssetCheckoutOrchestrator::ERROR_RESERVE_FAILED, $result['error']);
        self::assertSame($before, $result['cash_attempt_count']);
    }

    public function testCashFailureReleasesAssetReservation(): void
    {
        $this->cash->failNextAttempt();
        $result = $this->orchestrator->pay('pay-1');
        self::assertFalse($result['ok']);
        self::assertSame(CustomerAssetCheckoutOrchestrator::ERROR_CASH_FAILED, $result['error']);
        self::assertTrue($result['reservation_released']);
        self::assertSame(0, $this->assets->getBalance('cust-a', 0, 'credit')['reserved_minor']);
        self::assertSame(500, $this->assets->getBalance('cust-a', 0, 'credit')['reservable_minor']);
    }

    public function testCashSuccessCommitFailureCanRetryWithoutRecashing(): void
    {
        $this->orchestrator->failNextCommit();
        $first = $this->orchestrator->pay('pay-1');
        self::assertFalse($first['ok']);
        self::assertSame(CustomerAssetCheckoutOrchestrator::ERROR_COMMIT_FAILED, $first['error']);
        self::assertTrue($first['pending_commit']);
        self::assertSame(1, $first['cash_attempt_count']);

        $retry = $this->orchestrator->retryCommit(
            'pay-1',
            (string) $first['reservation_id'],
            (string) $first['account_id'],
            (string) $first['cash_attempt_id'],
        );
        self::assertTrue($retry['ok'], json_encode($retry));
        self::assertTrue($retry['cash_not_retried']);
        self::assertSame(1, $this->cash->attemptCount());
        self::assertSame(0, $this->assets->getBalance('cust-a', 0, 'credit')['available_minor']);
        self::assertSame(1, $this->orchestrator->snapshots()->count());
    }

    public function testRefundReturnRetryDoesNotReRefundCash(): void
    {
        $paid = $this->orchestrator->pay('pay-1');
        self::assertTrue($paid['ok']);

        $refunds = CustomerAssetRefundReturnService::forTesting($this->assets, $this->cash);
        $refunds->failNextReturn();
        $first = $refunds->start([
            'order_ref' => (string) $paid['order_ref'],
            'account_id' => (string) $paid['account_id'],
            'cash_attempt_id' => (string) $paid['cash_attempt_id'],
            'asset_amount_minor' => 500,
            'cash_amount_minor' => 500,
        ]);
        self::assertFalse($first['ok']);
        self::assertTrue($first['pending_asset_return']);
        self::assertSame(1, $first['cash_refund_count']);

        $retry = $refunds->retryReturn((string) $paid['order_ref']);
        self::assertTrue($retry['ok']);
        self::assertTrue($retry['cash_not_retried']);
        self::assertSame(1, $this->cash->refundCount());
        self::assertSame(500, $this->assets->getBalance('cust-a', 0, 'credit')['available_minor']);
    }

    public function testModeOffBlocksNewPayButAllowsExistingReleaseAndReturn(): void
    {
        // Create an open reservation then mode off
        $rsv = $this->assets->reserve([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 100,
            'event_id' => 'open-rsv',
        ]);
        $this->assets->modeOff();

        try {
            $this->orchestrator->pay('pay-1');
            self::fail('expected mode off');
        } catch (CustomerAssetConflictException $e) {
            self::assertSame(CustomerAssetCheckoutOrchestrator::ERROR_MODE_OFF, $e->errorCode);
        }

        $released = $this->assets->release(
            (string) $rsv['reservation']['reservation_id'],
            'release-after-mode-off',
        );
        self::assertTrue($released['ok']);

        // Commit path under mode off for existing obligation after a successful pay before off:
        $this->assets->enableAllowlist(['website:0']);
        $this->assets->credit([
            'customer_id' => 'cust-b',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 200,
            'event_id' => 'credit-b',
        ]);
        $orch2 = CustomerAssetCheckoutOrchestrator::forTesting($this->assets, ArrayCashAttemptPort::forTesting());
        $orch2->registerPayable([
            'payable_id' => 'pay-b',
            'customer_id' => 'cust-b',
            'website_id' => 0,
            'total_minor' => 200,
            'asset_amount_minor' => 200,
        ]);
        $paid = $orch2->pay('pay-b');
        self::assertTrue($paid['ok']);
        $this->assets->modeOff();

        $refunds = CustomerAssetRefundReturnService::forTesting($this->assets, $orch2->cash());
        $ret = $refunds->start([
            'order_ref' => (string) $paid['order_ref'],
            'account_id' => (string) $paid['account_id'],
            'cash_attempt_id' => (string) $paid['cash_attempt_id'],
            'asset_amount_minor' => 200,
            'cash_amount_minor' => 0,
        ]);
        self::assertTrue($ret['ok'], json_encode($ret));
        self::assertSame(200, $this->assets->getBalance('cust-b', 0, 'credit')['available_minor']);
    }

    public function testHappyPathWritesImmutableSnapshot(): void
    {
        $paid = $this->orchestrator->pay('pay-1');
        self::assertTrue($paid['ok']);
        $snap = $this->orchestrator->snapshots()->get((string) $paid['order_ref']);
        self::assertNotNull($snap);
        self::assertSame(500, $snap->assetAmountMinor);
        self::assertSame(500, $snap->cashAmountMinor);

        $this->assets->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 999,
            'event_id' => 'later-credit',
        ]);
        $again = $this->orchestrator->snapshots()->get((string) $paid['order_ref']);
        self::assertSame(500, $again->assetAmountMinor);
        self::assertSame($snap->hash, $again->hash);
    }
}
