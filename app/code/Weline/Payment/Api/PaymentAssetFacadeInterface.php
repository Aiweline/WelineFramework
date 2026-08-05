<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\Data\PaymentStartCommand;

/**
 * Payment-owned durable allocation boundary.
 *
 * The callback receives the cash-only frozen snapshot and must create either a
 * normal Payment attempt or a zero-cash intent. The implementation keeps asset
 * reserve, Payment allocation and Payment start in one default-connector
 * boundary.
 */
interface PaymentAssetFacadeInterface
{
    /**
     * @param callable(PayableSnapshot): array<string, mixed> $beginPayment
     * @return array{
     *   payment:array<string,mixed>,
     *   allocations:list<array<string,mixed>>,
     *   cash_snapshot:PayableSnapshot
     * }
     */
    public function startWithAssets(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        callable $beginPayment,
    ): array;

    /** @return list<array<string, mixed>> */
    public function listByIntent(string $intentCode): array;

    /** @return list<array<string, mixed>> */
    public function listByAttempt(string $attemptCode): array;

    /** @return array<string, mixed> */
    public function applyTerminalEffect(PaymentEffectRecord $effect): array;

    /**
     * Return committed tender from one frozen Order refund allocation.
     *
     * @param list<array<string, mixed>> $allocations
     * @return array<string, mixed>
     */
    public function returnCommittedAllocations(
        string $refundCaseUuid,
        array $allocations,
        string $effectKey,
    ): array;
}
