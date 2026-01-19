<?php

declare(strict_types=1);

namespace WeShop\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Shipping\Interface\ShippingProviderInterface;

/**
 * 物流服务
 */
class ShippingService
{
    /**
     * 计算运费
     * 
     * @param array $shippingData 物流数据（地址、重量、体积等）
     * @param string $shippingMethod 物流方式
     * @return float
     */
    public function calculateShipping(array $shippingData, string $shippingMethod): float
    {
        $provider = $this->getProvider($shippingMethod);
        
        if (!$provider) {
            throw new \Exception(__('不支持的物流方式: %{1}', [$shippingMethod]));
        }
        
        return $provider->calculateShipping($shippingData);
    }
    
    /**
     * 获取物流提供者
     * 
     * @param string $method 物流方式
     * @return ShippingProviderInterface|null
     */
    protected function getProvider(string $method): ?ShippingProviderInterface
    {
        $providerClass = "WeShop\\Shipping\\Provider\\" . ucfirst($method);
        
        if (class_exists($providerClass)) {
            try {
                $provider = ObjectManager::getInstance($providerClass);
                if ($provider instanceof ShippingProviderInterface) {
                    return $provider;
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        return null;
    }
    
    /**
     * 获取可用的物流方式列表
     * 
     * @return array
     */
    public function getAvailableShippingMethods(): array
    {
        return [
            'standard' => '标准物流',
            'express' => '快递',
            'overnight' => '次日达',
        ];
    }
}
