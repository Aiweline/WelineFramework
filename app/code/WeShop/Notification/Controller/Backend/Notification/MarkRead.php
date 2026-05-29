<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Backend\Notification;

use WeShop\Notification\Service\NotificationService;
use Weline\Admin\Controller\BaseController;

class MarkRead extends BaseController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(): string
    {
        $notificationId = (int) ($this->request->getParam('notification_id') ?? $this->request->getParam('id') ?? 0);
        $backUrl = (string) ($this->request->getParam('back_url') ?? $this->getBackendUrl('*/backend/notification'));

        if ($notificationId <= 0) {
            $this->getMessageManager()->addError(__('通知ID不能为空。'));
            $this->redirect($backUrl);
            return '';
        }

        if ($this->notificationService->markAsRead($notificationId)) {
            $this->getMessageManager()->addSuccess(__('通知已标记为已读。'));
        } else {
            $this->getMessageManager()->addError(__('通知标记已读失败。'));
        }

        $this->redirect($backUrl);
        return '';
    }

    public function post(): string
    {
        return $this->index();
    }
}

