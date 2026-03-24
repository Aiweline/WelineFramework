<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class ManualTransfer implements PaymentProviderInterface
{
    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        return [
            'status' => 'pending',
            'requires_action' => false,
            'redirect_url' => '',
            'instructions' => (string) __('Please complete a manual bank transfer after the order is placed.'),
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
