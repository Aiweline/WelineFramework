<?php

declare(strict_types=1);

namespace WeShop\Logistics\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Logistics\Model\Tracking;

/**
 * 物流追踪服务
 */
class TrackingService
{
    /**
     * 添加物流追踪记录
     */
    public function addTracking(int $orderId, string $trackingNumber, string $carrier, array $trackingData = []): Tracking
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        
        $tracking->setData([
            Tracking::schema_fields_order_id => $orderId,
            Tracking::schema_fields_tracking_number => $trackingNumber,
            Tracking::schema_fields_carrier => $carrier,
            Tracking::schema_fields_status => $trackingData['status'] ?? '',
            Tracking::schema_fields_location => $trackingData['location'] ?? '',
            Tracking::schema_fields_description => $trackingData['description'] ?? '',
            Tracking::schema_fields_tracked_at => $trackingData['tracked_at'] ?? date('Y-m-d H:i:s'),
            Tracking::schema_fields_created_at => date('Y-m-d H:i:s'),
            Tracking::schema_fields_updated_at => date('Y-m-d H:i:s'),
        ])->save();
        
        return $tracking;
    }
    
    /**
     * 获取订单物流追踪记录
     */
    public function getOrderTracking(int $orderId): array
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        
        return $tracking->clear()
            ->where(Tracking::schema_fields_order_id, $orderId)
            ->order(Tracking::schema_fields_tracked_at, 'DESC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 更新物流状态
     */
    public function updateTrackingStatus(int $trackingId, string $status, string $location = '', string $description = ''): Tracking
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        $tracking->load($trackingId);
        
        if ($tracking->getId()) {
            $tracking->setStatus($status)
                ->setLocation($location)
                ->setDescription($description)
                ->setTrackedAt(date('Y-m-d H:i:s'))
                ->setUpdatedAt(date('Y-m-d H:i:s'))
                ->save();
        }
        
        return $tracking;
    }
}
