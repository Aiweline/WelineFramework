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
            $this->getMessageManager()->addError(__('Notification ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        if ($this->notificationService->markAsRead($notificationId)) {
            $this->getMessageManager()->addSuccess(__('Notification marked as read.'));
        } else {
            $this->getMessageManager()->addError(__('Notification could not be marked as read.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    public function post(): string
    {
        return $this->index();
    }
}

