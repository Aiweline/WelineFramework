<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class ManualTransfer implements PaymentProviderInterface
{
    use ProviderContextHelperTrait;

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $instructions = $this->readConfigString($context, 'instructions');
        if ($instructions === '') {
            $instructions = (string) __('请在订单创建后完成手动银行转账。');
        }

        $amount = $this->readOrderAmount($order, $paymentData);
        $currency = $this->resolveCurrency($context, $paymentData);
        $orderNumber = $this->readOrderNumber($order);

        return [
            'status' => 'pending',
            'requires_action' => false,
            'redirect_url' => '',
            'instructions' => $instructions,
            'order_number' => $orderNumber,
            'amount' => (float) $amount,
            'currency' => $currency,
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        // Manual transfer requires admin confirmation, callback always returns true
        // Actual payment confirmation happens through admin backend
        return true;
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $inlineStatus = strtoupper(
            (string) ($context['trade_status'] ?? $context['status'] ?? '')
        );

        if ($inlineStatus !== '') {
            return $this->mapTradeStatus($inlineStatus);
        }

        return 'pending';
    }

    protected function mapTradeStatus(string $tradeStatus): string
    {
        return match ($tradeStatus) {
            'CONFIRMED', 'ADMIN_CONFIRMED', 'PAID', 'SUCCESS' => 'paid',
            'PENDING', 'WAITING' => 'pending',
            'FAILED', 'CANCELLED', 'REJECTED' => 'failed',
            'REFUNDED', 'REFUND', 'REFUND_SUCCESS' => 'refunded',
            default => 'pending',
        };
    }

    protected function resolveCurrency(array $context, array $paymentData): string
    {
        $currency = trim((string) ($paymentData['currency'] ?? $context['currency'] ?? ''));
        if ($currency !== '') {
            return strtoupper($currency);
        }

        $config = $this->readConfig($context);
        $currency = trim((string) ($config['currency'] ?? ''));

        return $currency !== '' ? strtoupper($currency) : 'USD';
    }
}
