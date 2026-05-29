<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Frontend\Notification;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Notification\Service\NotificationPageDataService;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';
    private const ACCOUNT_NOTIFICATION_ROUTE = 'customer/account/index#notification-preferences';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly NotificationPageDataService $notificationPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('请先登录后再继续。'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $this->redirect(self::ACCOUNT_NOTIFICATION_ROUTE);
        return '';
    }
}
