<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Affiliate\Model\Affiliate;

/**
 * 分销联盟服务
 */
class AffiliateService
{
    /**
     * 创建分销账户
     * 
     * @param int $customerId 客户ID
     * @return Affiliate
     */
    public function createAffiliate(int $customerId): Affiliate
    {
        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        
        // 检查是否已存在
        $existing = $affiliate->clear()
            ->where(Affiliate::schema_fields_CUSTOMER_ID, $customerId)
            ->find()
            ->fetch();
        
        if ($existing && $existing->getId()) {
            return $existing;
        }
        
        // 生成推荐码
        $referralCode = $this->generateReferralCode($customerId);
        
        $affiliate->clearData()
            ->setData(Affiliate::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Affiliate::schema_fields_REFERRAL_CODE, $referralCode)
            ->setData(Affiliate::schema_fields_COMMISSION_RATE, 0.1) // 默认10%
            ->setData(Affiliate::schema_fields_STATUS, 'active')
            ->save();
        
        return $affiliate;
    }
    
    /**
     * 生成推荐码
     * 
     * @param int $customerId 客户ID
     * @return string
     */
    protected function generateReferralCode(int $customerId): string
    {
        return 'REF' . str_pad((string)$customerId, 8, '0', STR_PAD_LEFT) . strtoupper(substr(uniqid(), -4));
    }
    
    /**
     * 计算佣金
     * 
     * @param string $referralCode 推荐码
     * @param float $orderTotal 订单总额
     * @return float
     */
    public function calculateCommission(string $referralCode, float $orderTotal): float
    {
        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $affiliate->load(Affiliate::schema_fields_REFERRAL_CODE, $referralCode);
        
        if (!$affiliate->getId() || $affiliate->getData(Affiliate::schema_fields_STATUS) !== 'active') {
            return 0;
        }
        
        $rate = (float)$affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE);
        return $orderTotal * $rate;
    }
}
