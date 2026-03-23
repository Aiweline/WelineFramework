<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class CashOnDelivery implements PaymentProviderInterface
{
    public function processPayment(Order $order, array $paymentData = []): array
    {
        return [
            'status' => 'pending',
            'requires_action' => false,
            'redirect_url' => '',
            'instructions' => (string) __('Collect payment from the customer when the shipment is delivered.'),
        ];
    }

    public function handleCallback(array $callbackData): bool
    {
        return true;
    }

    public function queryPaymentStatus(string $orderNumber): string
    {
        return 'pending';
    }
}
