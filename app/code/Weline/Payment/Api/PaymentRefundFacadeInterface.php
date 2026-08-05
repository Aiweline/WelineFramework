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
}
