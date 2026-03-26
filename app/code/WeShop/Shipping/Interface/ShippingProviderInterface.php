<?php

declare(strict_types=1);

namespace WeShop\Shipping\Interface;

/**
 * 物流提供者接口
 */
interface ShippingProviderInterface
{
    public function getName(): string;

    public function getCode(): string;

    public function isEnabled(): bool;

    /**
     * 计算运费
     * 
     * @param array $shippingData 物流数据（地址、重量、体积等）
     * @return float 运费
     */
    public function calculateShipping(array $shippingData): float;
    
    /**
     * 创建物流单
     * 
     * @param array $orderData 订单数据
     * @return string 物流单号
     */
    public function createShipping(array $orderData): string;
    
    /**
     * 查询物流状态
     * 
     * @param string $trackingNumber 物流单号
     * @return array 物流状态信息
     */
    public function trackShipping(string $trackingNumber): array;
}
