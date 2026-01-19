<?php

declare(strict_types=1);

namespace WeShop\B2B\Interface;

/**
 * 信用提供者接口
 */
interface CreditProviderInterface
{
    /**
     * 获取信用额度
     * 
     * @param int $companyId 公司ID
     * @return float
     */
    public function getCreditLimit(int $companyId): float;
    
    /**
     * 获取已使用额度
     * 
     * @param int $companyId 公司ID
     * @return float
     */
    public function getUsedCredit(int $companyId): float;
    
    /**
     * 检查信用额度是否足够
     * 
     * @param int $companyId 公司ID
     * @param float $amount 金额
     * @return bool
     */
    public function checkCredit(int $companyId, float $amount): bool;
}
