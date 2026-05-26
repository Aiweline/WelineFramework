<?php

declare(strict_types=1);

namespace WeShop\Payment\Interface;

use WeShop\Order\Model\Order;

interface PaymentRefundProviderInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function refundPayment(Order $order, float $amount, string $providerReference, array $context = []): array;
}
