<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Order\Model\Order;

/**
 * 微信支付提供者
 */
class WeChatPay implements PaymentProviderInterface
{
    /**
     * @inheritDoc
     */
    public function processPayment(Order $order, array $paymentData = []): array
    {
        // TODO: 实现微信支付逻辑
        return [
            'payment_url' => '',
            'payment_params' => [],
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function handleCallback(array $callbackData): bool
    {
        // TODO: 实现微信支付回调处理
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function queryPaymentStatus(string $orderNumber): string
    {
        // TODO: 实现支付状态查询
        return 'pending';
    }
}
