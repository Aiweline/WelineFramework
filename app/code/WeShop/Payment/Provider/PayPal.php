<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class PayPal implements PaymentProviderInterface
{
    public function processPayment(Order $order, array $paymentData = []): array
    {
        $orderNumber = '';
        if (method_exists($order, 'getIncrementId')) {
            $orderNumber = (string) $order->getIncrementId();
        } elseif (defined(Order::class . '::schema_fields_increment_id')) {
            $orderNumber = (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
        }

        return [
            'status' => 'pending',
            'requires_action' => true,
            'redirect_url' => 'https://www.paypal.com/checkoutnow?token=' . rawurlencode($orderNumber !== '' ? $orderNumber : (string) $order->getId()),
            'payment_params' => [
                'intent' => 'CAPTURE',
                'order_reference' => $orderNumber !== '' ? $orderNumber : (string) $order->getId(),
            ],
        ];
    }

    public function handleCallback(array $callbackData): bool
    {
        return !empty($callbackData);
    }

    public function queryPaymentStatus(string $orderNumber): string
    {
        return 'pending';
    }
}
