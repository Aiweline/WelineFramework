<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Service\NotificationService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::notification', '通知中心', 'ri-notification-3-line', '查看系统通知', 'Weline_Backend::system_settings')]
class Notification extends BackendController
{
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = ObjectManager::getInstance(NotificationService::class);
    }

    #[Acl('Weline_Backend::notification_index', '通知列表', 'ri-list-check', '查看通知列表')]
    public function index(): string
    {
        $userId = (int) $this->session->getLoginUserId();
        $page = (int) $this->request->getGet('page', 1);
        $limit = 15;

        $result = $this->notificationService->getUserNotifications($userId, $page, $limit);

        $this->assign('notifications', $result['items']);
        $this->assign('pagination', [
            'page'  => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
        ]);
        $this->assign('page_title', __('通知中心'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_detail', '通知详情', 'ri-file-text-line', '查看通知详情')]
    public function detail(): string
    {
        $userId = (int) $this->session->getLoginUserId();
        $notificationId = (int) $this->request->getGet('id', 0);

        if (!$notificationId) {
            return $this->redirect($this->getBackendUrl('*/backend/notification'));
        }

        $notification = $this->notificationService->getNotificationDetail($userId, $notificationId);

        if (!$notification) {
            $this->assign('error', __('通知不存在或无权查看'));
            return $this->fetch('Weline_Backend::Backend/Notification/error.phtml');
        }

        $this->notificationService->markAsRead($userId, $notificationId);

        $this->assign('notification', $notification);
        $this->assign('page_title', $notification['title'] ?? __('通知详情'));

        return $this->fetch();
    }
}
