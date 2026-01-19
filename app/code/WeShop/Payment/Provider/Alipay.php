<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Order\Model\Order;
use Weline\Framework\Manager\ObjectManager;

/**
 * 支付宝支付提供者
 */
class Alipay implements PaymentProviderInterface
{
    /**
     * @inheritDoc
     */
    public function processPayment(Order $order, array $paymentData = []): array
    {
        // TODO: 实现支付宝支付逻辑
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
        // TODO: 实现支付宝回调处理
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
