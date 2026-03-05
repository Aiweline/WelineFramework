<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Service\NotificationService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
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

        $keyword = $this->request->getGet('keyword', '');
        $type = $this->request->getGet('type', '');
        $read = $this->request->getGet('read', 'all');
        $filters = [
            'keyword' => $keyword,
            'type'    => $type,
            'read'    => in_array($read, ['all', 'read', 'unread'], true) ? $read : 'all',
        ];

        $result = $this->notificationService->getUserNotifications($userId, $page, $limit, false, $filters);

        $this->assign('notifications', $result['items']);
        $this->assign('pagination', [
            'page'  => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
        ]);
        $this->assign('filter', $filters);
        $this->assign('type_options', \Weline\Backend\Enum\NotificationType::getTypeOptions());
        $this->assign('page_title', __('通知中心'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_detail', '通知详情', 'ri-file-text-line', '查看通知详情')]
    public function detail(): string
    {
        $userId = (int) $this->session->getLoginUserId();
        $notificationId = (int) $this->request->getGet('id', 0);

        if (!$notificationId) {
            return $this->redirect($this->getBackendUrl('system/backend/notification'));
        }

        $notification = $this->notificationService->getNotificationDetail($userId, $notificationId);

        if (!$notification) {
            $this->assign('error', __('通知不存在或无权查看'));
            return $this->fetch('Weline_Backend::Backend/Notification/error.phtml');
        }

        $this->notificationService->markAsRead($userId, $notificationId);

        $adjacent = $this->notificationService->getAdjacentNotifications($userId, $notificationId);
        $this->assign('notification', $notification);
        $this->assign('prev_id', $adjacent['prev_id']);
        $this->assign('prev_title', $adjacent['prev_title']);
        $this->assign('next_id', $adjacent['next_id']);
        $this->assign('next_title', $adjacent['next_title']);
        $this->assign('page_title', $notification['title'] ?? __('通知详情'));

        return $this->fetch();
    }

    /**
     * 标记单条通知为已读（JSON API，供头部/下拉调用）
     * POST system/backend/notification/markRead
     */
    public function markRead(): void
    {
        $userId = (int) $this->session->getLoginUserId();
        $notificationId = (int) $this->request->getBodyParam('notification_id', 0);

        if (!$notificationId || !$userId) {
            $this->jsonResponse(400, false, __('参数错误'));
        }

        $success = $this->notificationService->markAsRead($userId, $notificationId);
        $this->jsonResponse(200, $success, $success ? __('已标记为已读') : __('标记失败'));
    }

    /**
     * 标记全部通知为已读（JSON API，供头部「全部已读」调用）
     * POST system/backend/notification/markAllRead
     */
    public function markAllRead(): void
    {
        $userId = (int) $this->session->getLoginUserId();

        if (!$userId) {
            $this->jsonResponse(401, false, __('未登录'));
        }

        $this->notificationService->markAllAsRead($userId);
        $this->jsonResponse(200, true, __('已标记全部已读'));
    }

    /**
     * 输出 JSON 并终止请求（与 Api/Notification 返回格式一致）
     */
    private function jsonResponse(int $code, bool $success, string $message): void
    {
        $body = json_encode([
            'code'    => $code,
            'success' => $success,
            'message' => $message,
            'data'    => [],
        ], JSON_UNESCAPED_UNICODE);

        throw new ResponseTerminateException(
            $code,
            $body,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
