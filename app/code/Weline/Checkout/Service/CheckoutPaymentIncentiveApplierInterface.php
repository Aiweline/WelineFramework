<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Payment-method incentive hook used by CheckoutOrderPaymentService.
 *
 * An interface keeps unit tests isolated from live payment-method config.
 */
interface CheckoutPaymentIncentiveApplierInterface
{
    /** @param array<string, mixed> $orderData @return array<string, mixed> */
    public function applyToPaymentContext(string $paymentMethod, array $orderData): array;
}
