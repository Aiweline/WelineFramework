<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Notification\Model\Notification;

/**
 * 通知服务
 */
class NotificationService
{
    /**
     * 发送通知
     * 
     * @param array $notificationData 通知数据
     * @return Notification
     */
    public function sendNotification(array $notificationData): Notification
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);
        
        $notification->clearData()
            ->setData('customer_id', $notificationData['customer_id'] ?? 0)
            ->setData('type', $notificationData['type'] ?? 'info')
            ->setData('title', $notificationData['title'] ?? '')
            ->setData('content', $notificationData['content'] ?? '')
            ->setData('is_read', 0)
            ->save();
        
        return $notification;
    }
    
    /**
     * 获取客户通知列表
     * 
     * @param int $customerId 客户ID
     * @param bool $unreadOnly 仅未读
     * @return array
     */
    public function getCustomerNotifications(int $customerId, bool $unreadOnly = false): array
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);
        
        $notification->clear()
            ->where('customer_id', $customerId);
        
        if ($unreadOnly) {
            $notification->where('is_read', 0);
        }
        
        return $notification->order('created_at', 'DESC')
            ->select()
            ->fetchArray();
    }
}
