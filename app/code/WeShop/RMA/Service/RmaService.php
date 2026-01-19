<?php

declare(strict_types=1);

namespace WeShop\RMA\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\RMA\Model\Rma;

/**
 * 退货服务
 */
class RmaService
{
    /**
     * 创建退货申请
     * 
     * @param array $rmaData 退货数据
     * @return Rma
     */
    public function createRma(array $rmaData): Rma
    {
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);
        
        $rma->clearData()
            ->setData('order_id', $rmaData['order_id'] ?? 0)
            ->setData('customer_id', $rmaData['customer_id'] ?? 0)
            ->setData('reason', $rmaData['reason'] ?? '')
            ->setData('description', $rmaData['description'] ?? '')
            ->setData('status', 'pending')
            ->save();
        
        return $rma;
    }
    
    /**
     * 获取客户退货列表
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCustomerRmas(int $customerId): array
    {
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);
        
        return $rma->clear()
            ->where('customer_id', $customerId)
            ->order('created_at', 'DESC')
            ->select()
            ->fetchArray();
    }
}
