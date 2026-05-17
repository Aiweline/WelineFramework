<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class CashOnDelivery implements PaymentProviderInterface
{
    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        return [
            'status' => 'pending',
            'requires_action' => false,
            'redirect_url' => '',
            'instructions' => (string) __('配送送达时向客户收款。'),
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        return true;
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        return 'pending';
    }
}
