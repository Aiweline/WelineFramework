<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Frontend\Notification;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class MarkRead extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly NotificationService $notificationService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录后再继续。'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $notificationId = $this->readNotificationId();
        if ($notificationId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('通知ID不能为空。'),
            ]);
        }

        $marked = $this->notificationService->markAsRead($notificationId, $customerId);
        if (!$marked) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('通知标记已读失败。'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('通知已标记为已读。'),
            'data' => [
                'notification_id' => $notificationId,
                'unread_count' => $this->notificationService->getUnreadCount($customerId),
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function readNotificationId(): int
    {
        return (int) (
            $this->request->body('notification_id')
            ?? $this->request->body('item_id')
            ?? $this->request->getPost('notification_id')
            ?? $this->request->getPost('item_id')
            ?? $this->request->getParam('notification_id')
            ?? $this->request->getParam('item_id')
            ?? 0
        );
    }
}
