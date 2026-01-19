<?php

declare(strict_types=1);

namespace WeShop\Membership\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Membership\Model\Membership;

/**
 * 会员服务
 */
class MembershipService
{
    /**
     * 获取客户会员等级
     * 
     * @param int $customerId 客户ID
     * @return Membership|null
     */
    public function getCustomerMembership(int $customerId): ?Membership
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);
        
        $membership->clear()
            ->where('customer_id', $customerId)
            ->find()
            ->fetch();
        
        if ($membership->getId()) {
            return $membership;
        }
        
        return null;
    }
    
    /**
     * 升级会员等级
     * 
     * @param int $customerId 客户ID
     * @param string $level 等级
     * @return Membership
     */
    public function upgradeMembership(int $customerId, string $level): Membership
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);
        
        $existing = $this->getCustomerMembership($customerId);
        if ($existing) {
            $membership = $existing;
        }
        
        $membership->setData('customer_id', $customerId)
            ->setData('level', $level)
            ->save();
        
        return $membership;
    }
}
