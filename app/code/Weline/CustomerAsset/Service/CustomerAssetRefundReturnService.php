<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Api\CashAttemptPortInterface;

/**
 * 资产返还与现金退款步骤独立；现金退款成功后资产返还可单独重试。
 */
final class CustomerAssetRefundReturnService
{
    public const ERROR_CASH_REFUND_FAILED = 'customer_asset_cash_refund_failed';
    public const ERROR_RETURN_FAILED = 'customer_asset_return_failed';

    private bool $failNextReturn = false;

    /** @var array<string, array<string, mixed>> */
    private array $jobs = [];

    public function __construct(
        private readonly CustomerAssetService $assets,
        private readonly CashAttemptPortInterface $cash,
    ) {
    }

    public static function forTesting(?CustomerAssetService $assets = null, ?CashAttemptPortInterface $cash = null): self
    {
        return new self(
            $assets ?? CustomerAssetService::forTesting(),
            $cash ?? ArrayCashAttemptPort::forTesting(),
        );
    }

    public function failNextReturn(): void
    {
        $this->failNextReturn = true;
    }

    /**
     * @param array{
     *   order_ref:string,
     *   account_id:string,
     *   cash_attempt_id:string,
     *   asset_amount_minor:int,
     *   cash_amount_minor:int
     * } $request
     * @return array<string, mixed>
     */
    public function start(array $request): array
    {
        $orderRef = (string) $request['order_ref'];
        $cashRefund = $this->cash->refund([
            'attempt_id' => (string) $request['cash_attempt_id'],
            'amount_minor' => (int) $request['cash_amount_minor'],
            'event_id' => 'cash-refund:' . $orderRef,
        ]);
        if (!($cashRefund['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => self::ERROR_CASH_REFUND_FAILED,
                'cash_refund_count' => $this->cash->refundCount(),
            ];
        }

        $this->jobs[$orderRef] = [
            'order_ref' => $orderRef,
            'account_id' => (string) $request['account_id'],
            'asset_amount_minor' => (int) $request['asset_amount_minor'],
            'cash_refund_id' => $cashRefund['refund_id'],
            'asset_returned' => false,
        ];

        $returned = $this->returnAsset($orderRef);
        if (!($returned['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => self::ERROR_RETURN_FAILED,
                'cash_refund_id' => $cashRefund['refund_id'],
                'cash_refund_count' => $this->cash->refundCount(),
                'pending_asset_return' => true,
                'order_ref' => $orderRef,
            ];
        }

        return [
            'ok' => true,
            'order_ref' => $orderRef,
            'cash_refund_id' => $cashRefund['refund_id'],
            'cash_refund_count' => $this->cash->refundCount(),
            'asset_returned' => true,
            'entry' => $returned['entry'] ?? null,
        ];
    }

    /**
     * 仅重试资产返还；不重退现金。
     *
     * @return array<string, mixed>
     */
    public function retryReturn(string $orderRef): array
    {
        $before = $this->cash->refundCount();
        $returned = $this->returnAsset($orderRef);
        if (!($returned['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => self::ERROR_RETURN_FAILED,
                'cash_refund_count' => $this->cash->refundCount(),
                'cash_not_retried' => $this->cash->refundCount() === $before,
                'pending_asset_return' => true,
            ];
        }

        return [
            'ok' => true,
            'order_ref' => $orderRef,
            'asset_returned' => true,
            'cash_refund_count' => $this->cash->refundCount(),
            'cash_not_retried' => $this->cash->refundCount() === $before,
            'entry' => $returned['entry'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnAsset(string $orderRef): array
    {
        $job = $this->jobs[$orderRef] ?? null;
        if ($job === null) {
            return ['ok' => false, 'error' => 'return_job_missing'];
        }
        if (!empty($job['asset_returned'])) {
            return [
                'ok' => true,
                'idempotent' => true,
                'entry' => $this->assets->ledger()->getByEvent('asset-return:' . $orderRef)?->toArray(),
            ];
        }

        if ($this->failNextReturn) {
            $this->failNextReturn = false;

            return ['ok' => false, 'error' => 'injected_return_failure'];
        }

        try {
            $result = $this->assets->returnFunds(
                (string) $job['account_id'],
                (int) $job['asset_amount_minor'],
                'asset-return:' . $orderRef,
                ['order_ref' => $orderRef],
            );
        } catch (CustomerAssetConflictException $e) {
            return ['ok' => false, 'error' => $e->errorCode];
        }

        $this->jobs[$orderRef]['asset_returned'] = true;

        return $result;
    }
}
