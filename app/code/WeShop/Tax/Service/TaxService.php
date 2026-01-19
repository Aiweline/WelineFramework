<?php

declare(strict_types=1);

namespace WeShop\Tax\Service;

/**
 * 税费服务
 */
class TaxService
{
    /**
     * 计算税费
     * 
     * @param float $subtotal 小计
     * @param string|null $country 国家代码
     * @param string|null $region 地区
     * @return float
     */
    public function calculateTax(float $subtotal, ?string $country = null, ?string $region = null): float
    {
        // 默认税率
        $taxRate = 0.1; // 10%
        
        // 触发税费计算事件，允许其他模块修改税率
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Tax::calculate_tax', [
            'subtotal' => $subtotal,
            'country' => $country,
            'region' => $region,
            'tax_rate' => &$taxRate,
        ]);
        
        return $subtotal * $taxRate;
    }
    
    /**
     * 获取税率
     * 
     * @param string|null $country 国家代码
     * @param string|null $region 地区
     * @return float
     */
    public function getTaxRate(?string $country = null, ?string $region = null): float
    {
        // 默认税率
        $taxRate = 0.1;
        
        // 可以根据国家/地区返回不同税率
        if ($country === 'CN') {
            $taxRate = 0.13; // 中国增值税13%
        }
        
        return $taxRate;
    }
}
