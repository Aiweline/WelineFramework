<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

use Weline\Payment\Api\Data\PaymentTransactionRecord;

/**
 * Stable orchestration boundary for modules that start a payment.
 */
interface PaymentFacadeInterface
{
    /**
     * Returns null only when the method is unknown or inactive for the context.
     * Provider and persistence failures are surfaced to the caller.
     *
     * @param array<string, mixed> $context
     */
    public function tryCreatePayment(string $methodCode, array $context = []): ?PaymentTransactionRecord;
}
