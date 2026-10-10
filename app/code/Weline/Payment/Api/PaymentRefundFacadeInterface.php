<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

use Weline\Payment\Api\Data\RefundOperationResult;
use Weline\Payment\Api\Data\RefundReserveCommand;

/**
 * Public Payment-owned refund port used by business modules.
 *
 * reserve()/applyChannelResult() join the caller's default-connector
 * transaction when one is active. submitToProvider() is a remote side-effect
 * boundary and MUST be called outside every database transaction.
 */
interface PaymentRefundFacadeInterface
{
    public function reserve(RefundReserveCommand $command): RefundOperationResult;

    public function submitToProvider(
        string $refundCode,
        string $providerRequestKey,
    ): RefundOperationResult;

    /**
     * @param array<string, mixed> $providerResponse
     */
    public function applyChannelResult(
        string $refundCode,
        string $channelStatus,
        ?string $providerRefundId = null,
        array $providerResponse = [],
    ): RefundOperationResult;

    public function findByRefundCaseUuid(string $refundCaseUuid): ?RefundOperationResult;

    public function getOccupiedAmountMinor(string $payableType, string $payableId): int;

    public function getCapturedAmountMinor(string $payableType, string $payableId): int;

    /**
     * Sum amount_minor across all captured/paid intents for the payable
     * (deposit + balance multi-intent). Excludes fully refunded intents.
     * Prefer this over getCapturedAmountMinor() when computing remaining due floors.
     */
    public function sumCapturedAmountMinor(string $payableType, string $payableId): int;

    /**
     * Mark incomplete (non-captured) intents for a payable as cancelled.
     * Does not touch captured/paid/refund* intents. Returns abandoned count.
     */
    public function abandonOpenIntentsForPayable(
        string $payableType,
        string $payableId,
        string $reason = 'admin_amount_adjusted',
    ): int;
}
