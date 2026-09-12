<?php

declare(strict_types=1);

namespace Weline\B2B\Api;

use Weline\Payment\Model\PaymentIntent;

/**
 * Soft cross-module entry for ToB hang payment lifecycle.
 * Payment / Checkout / Order Payable MUST depend on this Interface only
 * (not concrete B2BHangOrderService).
 */
interface B2BHangPaymentBridgeInterface
{
    /**
     * @return array<string,mixed>|null Hang projection after reconcile, or null when unavailable
     */
    public function reconcilePaymentSuccess(string $orderRef, string $purpose, string $intentCode): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function onDepositPaid(string $orderRef, string $intentCode): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function onBalancePaid(string $orderRef, string $intentCode): ?array;

    /**
     * @param array<string,mixed> $metadata
     */
    public function onPaymentIntentLifecycle(PaymentIntent $intent, array $metadata): void;

    /**
     * @return array<string,mixed>|null Hang as array, or null
     */
    public function getHangByOrderRef(string $orderRef): ?array;
}
