<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Frontend\Notification;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 标记通知已读控制器
 */
class MarkRead extends FrontendController
{
    /**
     * 标记已读
     */
    public function index(): string
    {
        try {
            $notificationId = (int)($this->request->getParam('notification_id') ?? 0);
            
            if (!$notificationId) {
                return $this->fetchJson(['success' => false, 'message' => __('通知ID不能为空')]);
            }
            
            /** @var NotificationService $notificationService */
            $notificationService = ObjectManager::getInstance(NotificationService::class);
            $notificationService->markAsRead($notificationId);
            
            return $this->fetchJson(['success' => true, 'message' => __('已标记为已读')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
