<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Api\B2BHangPaymentBridgeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentIntent;

/**
 * Default Bridge: delegates to B2BHangOrderService; soft-fails when hang missing.
 */
final class B2BHangPaymentBridge implements B2BHangPaymentBridgeInterface
{
    public function __construct(
        private readonly ?B2BHangOrderService $hangOrders = null,
    ) {
    }

    private function hang(): ?B2BHangOrderService
    {
        if ($this->hangOrders instanceof B2BHangOrderService) {
            return $this->hangOrders;
        }
        try {
            $svc = ObjectManager::getInstance(B2BHangOrderService::class);

            return $svc instanceof B2BHangOrderService ? $svc : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function reconcilePaymentSuccess(string $orderRef, string $purpose, string $intentCode): ?array
    {
        $hang = $this->hang();
        if ($hang === null) {
            return null;
        }
        try {
            return $hang->reconcilePaymentSuccess($orderRef, $purpose, $intentCode);
        } catch (\Throwable) {
            return null;
        }
    }

    public function onDepositPaid(string $orderRef, string $intentCode): ?array
    {
        $hang = $this->hang();
        if ($hang === null) {
            return null;
        }
        try {
            return $hang->onDepositPaid($orderRef, $intentCode);
        } catch (\Throwable) {
            return null;
        }
    }

    public function onBalancePaid(string $orderRef, string $intentCode): ?array
    {
        $hang = $this->hang();
        if ($hang === null) {
            return null;
        }
        try {
            return $hang->onBalancePaid($orderRef, $intentCode);
        } catch (\Throwable) {
            return null;
        }
    }

    public function onPaymentIntentLifecycle(PaymentIntent $intent, array $metadata): void
    {
        $hang = $this->hang();
        if ($hang === null) {
            return;
        }
        try {
            $hang->onPaymentIntentLifecycle($intent, $metadata);
        } catch (\Throwable) {
            // Soft-fail: payment SPI must not fail closed.
        }
    }

    public function getHangByOrderRef(string $orderRef): ?array
    {
        $hang = $this->hang();
        if ($hang === null) {
            return null;
        }
        try {
            return $hang->getByOrderRef($orderRef)?->toArray();
        } catch (\Throwable) {
            return null;
        }
    }
}
