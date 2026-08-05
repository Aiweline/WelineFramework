<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Api\CashAttemptPortInterface;
use Weline\CustomerAsset\Model\AssetOrderAllocationSnapshot;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * P4D-002：先预占资产，再现金 Attempt；现金失败释放资产；commit 可独立重试。
 */
final class CustomerAssetCheckoutOrchestrator
{
    public const ERROR_MODE_OFF = 'customer_asset_checkout_mode_off';
    public const ERROR_RESERVE_FAILED = 'customer_asset_checkout_reserve_failed';
    public const ERROR_CASH_FAILED = 'customer_asset_checkout_cash_failed';
    public const ERROR_COMMIT_FAILED = 'customer_asset_checkout_commit_failed';
    public const ERROR_PAYABLE_NOT_FOUND = 'customer_asset_checkout_payable_missing';

    /** @var array<string, array<string, mixed>> */
    private array $payables = [];

    private int $orderSeq = 0;
    private bool $failNextCommit = false;
    private int $commitFailuresInjected = 0;

    public function __construct(
        private readonly CustomerAssetService $assets,
        private readonly CashAttemptPortInterface $cash,
        private readonly AssetOrderSnapshotStore $snapshots,
    ) {
    }

    public static function forTesting(?CustomerAssetService $assets = null, ?CashAttemptPortInterface $cash = null): self
    {
        $assets ??= CustomerAssetService::forTesting();
        $cash ??= ArrayCashAttemptPort::forTesting();

        return new self($assets, $cash, AssetOrderSnapshotStore::forTesting());
    }

    public function assets(): CustomerAssetService
    {
        return $this->assets;
    }

    public function cash(): CashAttemptPortInterface
    {
        return $this->cash;
    }

    public function snapshots(): AssetOrderSnapshotStore
    {
        return $this->snapshots;
    }

    public function failNextCommit(): void
    {
        $this->failNextCommit = true;
    }

    /**
     * @param array{
     *   payable_id:string,
     *   customer_id:string,
     *   website_id:int,
     *   asset_code?:string,
     *   namespace?:string,
     *   total_minor:int,
     *   asset_amount_minor:int
     * } $payable
     */
    public function registerPayable(array $payable): void
    {
        $this->payables[(string) $payable['payable_id']] = $payable;
    }

    /**
     * @return array<string, mixed>
     */
    public function pay(string $payableId): array
    {
        $mode = $this->assets->rollout()->mode(CustomerAssetService::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_OFF) {
            throw new CustomerAssetConflictException(self::ERROR_MODE_OFF, 'new tender blocked');
        }

        $payable = $this->payables[$payableId] ?? null;
        if ($payable === null) {
            throw new CustomerAssetConflictException(self::ERROR_PAYABLE_NOT_FOUND, 'missing payable');
        }

        $assetAmount = (int) $payable['asset_amount_minor'];
        $total = (int) $payable['total_minor'];
        $cashAmount = max(0, $total - $assetAmount);
        $customerId = (string) $payable['customer_id'];
        $websiteId = (int) $payable['website_id'];
        $assetCode = (string) ($payable['asset_code'] ?? 'credit');
        $namespace = (string) ($payable['namespace'] ?? 'live');

        // 1) reserve asset first — failure means zero cash attempt
        try {
            $reserve = $this->assets->reserve([
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'asset_code' => $assetCode,
                'namespace' => $namespace,
                'amount_minor' => $assetAmount,
                'event_id' => 'reserve:' . $payableId,
            ]);
        } catch (CustomerAssetConflictException $e) {
            return [
                'ok' => false,
                'error' => self::ERROR_RESERVE_FAILED,
                'cause' => $e->errorCode,
                'cash_attempt_count' => $this->cash->attemptCount(),
                'order_ref' => null,
            ];
        }

        $reservationId = (string) $reserve['reservation']['reservation_id'];
        $accountId = (string) $reserve['account']['account_id'];

        // 2) cash attempt
        $cash = $this->cash->attempt([
            'payable_id' => $payableId,
            'amount_minor' => $cashAmount,
            'event_id' => 'cash:' . $payableId,
        ]);
        if (!($cash['ok'] ?? false)) {
            $this->assets->release($reservationId, 'release-on-cash-fail:' . $payableId);

            return [
                'ok' => false,
                'error' => self::ERROR_CASH_FAILED,
                'reservation_released' => true,
                'cash_attempt_count' => $this->cash->attemptCount(),
                'order_ref' => null,
                'reservation_id' => $reservationId,
            ];
        }

        // 3) commit asset (independently retryable)
        $commit = $this->commitAsset($reservationId, $payableId);
        if (!($commit['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => self::ERROR_COMMIT_FAILED,
                'cash_attempt_id' => $cash['attempt_id'],
                'cash_attempt_count' => $this->cash->attemptCount(),
                'reservation_id' => $reservationId,
                'account_id' => $accountId,
                'pending_commit' => true,
                'order_ref' => null,
            ];
        }

        return $this->finalizeOrder($payableId, $payable, $accountId, $reservationId, $assetAmount, $cashAmount, (string) $cash['attempt_id']);
    }

    /**
     * 现金已成功后，仅重试资产 commit（不重扣现金）。
     *
     * @return array<string, mixed>
     */
    public function retryCommit(string $payableId, string $reservationId, string $accountId, string $cashAttemptId): array
    {
        $payable = $this->payables[$payableId] ?? null;
        if ($payable === null) {
            throw new CustomerAssetConflictException(self::ERROR_PAYABLE_NOT_FOUND, 'missing payable');
        }

        $cashBefore = $this->cash->attemptCount();
        $commit = $this->commitAsset($reservationId, $payableId);
        if (!($commit['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => self::ERROR_COMMIT_FAILED,
                'cash_attempt_count' => $this->cash->attemptCount(),
                'cash_not_retried' => $this->cash->attemptCount() === $cashBefore,
                'pending_commit' => true,
            ];
        }

        $assetAmount = (int) $payable['asset_amount_minor'];
        $cashAmount = max(0, (int) $payable['total_minor'] - $assetAmount);

        return $this->finalizeOrder(
            $payableId,
            $payable,
            $accountId,
            $reservationId,
            $assetAmount,
            $cashAmount,
            $cashAttemptId,
        ) + ['cash_not_retried' => $this->cash->attemptCount() === $cashBefore];
    }

    /**
     * @return array<string, mixed>
     */
    private function commitAsset(string $reservationId, string $payableId): array
    {
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            $this->commitFailuresInjected++;

            return ['ok' => false, 'error' => 'injected_commit_failure'];
        }

        try {
            return $this->assets->commit($reservationId, 'commit:' . $payableId);
        } catch (CustomerAssetConflictException $e) {
            return ['ok' => false, 'error' => $e->errorCode];
        }
    }

    /**
     * @param array<string, mixed> $payable
     * @return array<string, mixed>
     */
    private function finalizeOrder(
        string $payableId,
        array $payable,
        string $accountId,
        string $reservationId,
        int $assetAmount,
        int $cashAmount,
        string $cashAttemptId,
    ): array {
        $this->orderSeq++;
        $orderRef = 'ord_asset_' . $this->orderSeq;
        $hash = hash('sha256', implode('|', [
            $orderRef,
            $payableId,
            $accountId,
            $reservationId,
            (string) $assetAmount,
            (string) $cashAmount,
        ]));
        $snapshot = new AssetOrderAllocationSnapshot(
            $orderRef,
            $payableId,
            $accountId,
            $reservationId,
            $assetAmount,
            $cashAmount,
            (string) ($payable['asset_code'] ?? 'credit'),
            (string) ($payable['namespace'] ?? 'live'),
            $hash,
            ['cash_attempt_id' => $cashAttemptId],
        );
        $this->snapshots->put($snapshot);

        return [
            'ok' => true,
            'order_ref' => $orderRef,
            'snapshot' => $snapshot->toArray(),
            'cash_attempt_id' => $cashAttemptId,
            'cash_attempt_count' => $this->cash->attemptCount(),
            'reservation_id' => $reservationId,
            'account_id' => $accountId,
        ];
    }
}
