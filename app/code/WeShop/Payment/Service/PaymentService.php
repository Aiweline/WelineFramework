<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Order\Model\Order;

/**
 * 支付服务
 */
class PaymentService
{
    /**
     * 处理支付
     * 
     * @param Order $order 订单
     * @param string $paymentMethod 支付方式
     * @param array $paymentData 支付数据
     * @return array
     */
    public function processPayment(Order $order, string $paymentMethod, array $paymentData = []): array
    {
        $provider = $this->getProvider($paymentMethod);
        
        if (!$provider) {
            throw new \Exception(__('不支持的支付方式: %{1}', [$paymentMethod]));
        }
        
        return $provider->processPayment($order, $paymentData);
    }
    
    /**
     * 处理支付回调
     * 
     * @param string $paymentMethod 支付方式
     * @param array $callbackData 回调数据
     * @return bool
     */
    public function handleCallback(string $paymentMethod, array $callbackData): bool
    {
        $provider = $this->getProvider($paymentMethod);
        
        if (!$provider) {
            throw new \Exception(__('不支持的支付方式: %{1}', [$paymentMethod]));
        }
        
        return $provider->handleCallback($callbackData);
    }
    
    /**
     * 获取支付提供者
     * 
     * @param string $method 支付方式
     * @return PaymentProviderInterface|null
     */
    protected function getProvider(string $method): ?PaymentProviderInterface
    {
        $providerClass = "WeShop\\Payment\\Provider\\" . ucfirst($method);
        
        if (class_exists($providerClass)) {
            try {
                $provider = ObjectManager::getInstance($providerClass);
                if ($provider instanceof PaymentProviderInterface) {
                    return $provider;
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        return null;
    }
    
    /**
     * 获取可用的支付方式列表
     * 
     * @return array
     */
    public function getAvailablePaymentMethods(): array
    {
        return [
            'alipay' => '支付宝',
            'wechatpay' => '微信支付',
            'paypal' => 'PayPal',
        ];
    }
}
