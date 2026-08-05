<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\RefundAssetReturnCapabilityInterface;
use Weline\Payment\Api\PaymentAssetFacadeInterface;

/**
 * Order-owned adapter keeps the dependency direction Order → Payment.
 */
final class PaymentRefundAssetReturnCapability implements
    RefundAssetReturnCapabilityInterface
{
    public function __construct(
        private readonly PaymentAssetFacadeInterface $paymentAssets,
    ) {
    }

    public function returnCommittedAllocations(
        string $refundCaseUuid,
        array $allocations,
        string $effectKey,
    ): array {
        return $this->paymentAssets->returnCommittedAllocations(
            $refundCaseUuid,
            $allocations,
            $effectKey,
        );
    }
}
