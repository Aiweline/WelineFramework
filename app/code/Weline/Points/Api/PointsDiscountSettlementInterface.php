<?php

declare(strict_types=1);

namespace Weline\Points\Api;

/**
 * Checkout points-discount reservation lifecycle.
 *
 * Reserve on payment start, commit on paid, release on failure/cancel, and
 * return committed points on refund.
 */
interface PointsDiscountSettlementInterface
{
    /**
     * @return array{ok:bool,idempotent?:bool,points?:int,apply_checkout_minor?:int,error?:string}
     */
    public function reserveForOrder(
        string $orderUuid,
        int $customerId,
        int $websiteId,
        string $namespace,
        int $points,
        int $applyCheckoutMinor,
        int $applyBaseMinor,
        string $checkoutCurrency,
        string $idempotencyKey,
    ): array;

    /** @return array{ok:bool,idempotent?:bool,error?:string} */
    public function commitForOrder(string $orderUuid, string $idempotencyKey): array;

    /** @return array{ok:bool,idempotent?:bool,error?:string} */
    public function releaseForOrder(string $orderUuid, string $idempotencyKey): array;

    /** @return array{ok:bool,returned_points:int,error?:string} */
    public function returnForRefund(
        string $orderUuid,
        int $refundCheckoutMinor,
        int $orderTotalCheckoutMinor,
        string $idempotencyKey,
    ): array;
}
