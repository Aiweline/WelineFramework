<?php

declare(strict_types=1);

namespace WeShop\Payment\Interface;

use WeShop\Order\Model\Order;

interface PaymentCaptureProviderInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function capturePayment(Order $order, string $providerReference, array $context = []): array;
}
