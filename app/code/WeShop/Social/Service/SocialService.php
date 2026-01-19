<?php

declare(strict_types=1);

namespace WeShop\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Social\Model\SocialShare;

/**
 * 社交分享服务
 */
class SocialService
{
    /**
     * 记录分享
     * 
     * @param array $shareData 分享数据
     * @return SocialShare
     */
    public function recordShare(array $shareData): SocialShare
    {
        /** @var SocialShare $share */
        $share = ObjectManager::getInstance(SocialShare::class);
        
        $share->clearData()
            ->setData('customer_id', $shareData['customer_id'] ?? 0)
            ->setData('product_id', $shareData['product_id'] ?? 0)
            ->setData('platform', $shareData['platform'] ?? '')
            ->save();
        
        return $share;
    }
}
