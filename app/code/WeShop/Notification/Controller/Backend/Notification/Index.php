<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Backend\Notification;

use WeShop\Notification\Service\NotificationAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly NotificationAdminPageDataService $notificationAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'customer_id' => $this->request->getParam('customer_id', ''),
            'type' => $this->request->getParam('type', ''),
            'is_read' => $this->request->getParam('is_read', ''),
            'title' => $this->request->getParam('title', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('Notification Management'),
                'notificationIndexUrl' => $this->getBackendUrl('*/backend/notification'),
                'notificationViewUrl' => $this->getBackendUrl('*/backend/notification/view'),
                'notificationMarkReadUrl' => $this->getBackendUrl('*/backend/notification/mark-read'),
            ],
            $this->notificationAdminPageDataService->getListData($page, $pageSize, $filters)
        ));

        return $this->fetchBase();
    }
}

