<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Api;

use Weline\Backend\Service\NotificationService;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Notification extends BackendRestController
{
    private NotificationService $notificationService;

    public function __construct(
        Request $request
    ) {
        $this->request = $request;
        $this->notificationService = ObjectManager::getInstance(NotificationService::class);
    }

    /**
     * 发送系统通知
     * POST /api_admin/backend/notification/send
     */
    public function send(): array
    {
        try {
            $topic = $this->request->getBodyParam('topic');
            $type = $this->request->getBodyParam('type');
            $title = $this->request->getBodyParam('title');
            $content = $this->request->getBodyParam('content');

            $topic = is_string($topic) ? trim($topic) : '';
            $type = is_string($type) ? trim($type) : 'info';
            $title = is_string($title) ? trim($title) : '';
            $content = is_string($content) ? trim($content) : '';

            if (empty($topic)) {
                return $this->error(__('消息主题不能为空'), '', 400);
            }
            if (empty($title)) {
                return $this->error(__('消息标题不能为空'), '', 400);
            }
            if (empty($content)) {
                return $this->error(__('消息内容不能为空'), '', 400);
            }

            $validTypes = ['info', 'success', 'warning', 'error', 'urgent'];
            if (!in_array($type, $validTypes, true)) {
                $type = 'info';
            }

            $options = [
                'priority'      => $this->request->getBodyParam('priority'),
                'metadata'      => $this->request->getBodyParam('metadata') ?? [],
                'icon'          => $this->request->getBodyParam('icon') ?? 'ri-notification-line',
                'notify_users'  => $this->request->getBodyParam('notify_users') ?? [],
                'source_module' => $this->request->getBodyParam('source_module') ?? '',
            ];

            w_msg($topic, $type, $title, $content, $options);

            return $this->success([], __('通知已发送'));
        } catch (\Exception $e) {
            return $this->error(__('发送通知失败：%{error}', ['error' => $e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取可用的消息类型
     * GET /api_admin/backend/notification/getTypes
     */
    public function getTypes(): array
    {
        return $this->success([
            'types' => [
                ['value' => 'info', 'label' => __('信息'), 'color' => '#50a5f1'],
                ['value' => 'success', 'label' => __('成功'), 'color' => '#34c38f'],
                ['value' => 'warning', 'label' => __('警告'), 'color' => '#f1b44c'],
                ['value' => 'error', 'label' => __('错误'), 'color' => '#f46a6a'],
                ['value' => 'urgent', 'label' => __('紧急'), 'color' => '#c92a2a'],
            ]
        ]);
    }

    /**
     * 标记通知为已读
     * POST /api_admin/backend/notification/markRead
     */
    public function markRead(): array
    {
        try {
            $notificationId = (int) $this->request->getBodyParam('notification_id');
            $userId = (int) $this->session->getLoginUserId();

            if (!$notificationId || !$userId) {
                return $this->error(__('参数错误'), '', 400);
            }

            $success = $this->notificationService->markAsRead($userId, $notificationId);

            if ($success) {
                return $this->success([], __('已标记为已读'));
            }

            return $this->error(__('标记失败'), '', 500);
        } catch (\Exception $e) {
            return $this->error(__('操作失败'), '', 500);
        }
    }

    /**
     * 标记所有通知为已读
     * POST /api_admin/backend/notification/markAllRead
     */
    public function markAllRead(): array
    {
        try {
            $userId = (int) $this->session->getLoginUserId();

            if (!$userId) {
                return $this->error(__('未登录'), '', 401);
            }

            $this->notificationService->markAllAsRead($userId);

            return $this->success([], __('已标记全部已读'));
        } catch (\Exception $e) {
            return $this->error(__('操作失败'), '', 500);
        }
    }
}
