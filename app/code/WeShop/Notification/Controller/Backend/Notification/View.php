<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Backend\Notification;

use WeShop\Notification\Service\NotificationAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly NotificationAdminPageDataService $notificationAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $notificationId = (int) $this->request->getParam('id', 0);
        if ($notificationId <= 0) {
            $this->getMessageManager()->addError(__('通知ID不能为空。'));
            $this->redirect('*/backend/notification');
            return '';
        }

        try {
            $detail = $this->notificationAdminPageDataService->getDetailData($notificationId);
        } catch (\InvalidArgumentException $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect('*/backend/notification');
            return '';
        }

        $this->assign([
            'title' => (string) __('通知详情'),
            'notification' => $detail['notification'],
            'notificationIndexUrl' => $this->getBackendUrl('*/backend/notification'),
            'notificationMarkReadUrl' => $this->getBackendUrl('*/backend/notification/mark-read'),
        ]);

        return $this->fetchBase();
    }
}

