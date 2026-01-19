<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Frontend\Notification;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 通知列表控制器
 */
class Index extends FrontendController
{
    /**
     * 通知列表
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        /** @var NotificationService $notificationService */
        $notificationService = ObjectManager::getInstance(NotificationService::class);
        $notifications = $notificationService->getCustomerNotifications($customer->getId(), 20);
        $unreadCount = $notificationService->getUnreadCount($customer->getId());
        
        $this->assign('notifications', $notifications);
        $this->assign('unreadCount', $unreadCount);
        
        return $this->fetch();
    }
}
