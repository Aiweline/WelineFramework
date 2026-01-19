<?php

declare(strict_types=1);

namespace WeShop\Payment\Interface;

use WeShop\Order\Model\Order;

/**
 * 支付提供者接口
 */
interface PaymentProviderInterface
{
    /**
     * 处理支付
     * 
     * @param Order $order 订单
     * @param array $paymentData 支付数据
     * @return array 支付结果（包含支付URL、支付参数等）
     */
    public function processPayment(Order $order, array $paymentData = []): array;
    
    /**
     * 处理支付回调
     * 
     * @param array $callbackData 回调数据
     * @return bool 是否支付成功
     */
    public function handleCallback(array $callbackData): bool;
    
    /**
     * 查询支付状态
     * 
     * @param string $orderNumber 订单号
     * @return string 支付状态
     */
    public function queryPaymentStatus(string $orderNumber): string;
}
