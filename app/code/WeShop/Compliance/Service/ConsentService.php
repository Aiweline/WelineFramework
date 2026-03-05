<?php

declare(strict_types=1);

namespace WeShop\Compliance\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Compliance\Model\CookieConsent;

/**
 * 合规服务
 */
class ConsentService
{
    /**
     * 保存Cookie同意
     * 
     * @param array $consentData 同意数据
     * @return CookieConsent
     */
    public function saveConsent(array $consentData): CookieConsent
    {
        /** @var CookieConsent $consent */
        $consent = ObjectManager::getInstance(CookieConsent::class);
        
        $consent->clearData()
            ->setData(CookieConsent::schema_fields_CUSTOMER_ID, $consentData['customer_id'] ?? 0)
            ->setData(CookieConsent::schema_fields_CONSENT_TYPE, $consentData['consent_type'] ?? 'cookie')
            ->setData(CookieConsent::schema_fields_IS_ACCEPTED, $consentData['is_accepted'] ?? 0)
            ->save();
        
        return $consent;
    }
    
    /**
     * 检查客户是否已同意
     * 
     * @param int $customerId 客户ID
     * @param string $consentType 同意类型
     * @return bool
     */
    public function hasConsented(int $customerId, string $consentType): bool
    {
        /** @var CookieConsent $consent */
        $consent = ObjectManager::getInstance(CookieConsent::class);
        
        $consent->clear()
            ->where(CookieConsent::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CookieConsent::schema_fields_CONSENT_TYPE, $consentType)
            ->where(CookieConsent::schema_fields_IS_ACCEPTED, 1)
            ->find()
            ->fetch();
        
        return $consent->getId() > 0;
    }
}
