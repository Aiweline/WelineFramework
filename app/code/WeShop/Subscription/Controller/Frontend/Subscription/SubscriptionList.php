<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 前台客户订阅列表
 */
class SubscriptionList extends FrontendController
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function index(): string
    {
        $customerId = (int)$this->session->getLoginCustomerId();

        if (!$customerId) {
            $this->redirect($this->getUrl('customer/account/login'));
            return '';
        }

        $page = (int)($this->request->getGet('page') ?: 1);
        $status = $this->request->getGet('status');

        $filters = [];
        if ($status) {
            $filters['status'] = $status;
        }

        $result = $this->subscriptionService->getCustomerSubscriptions($customerId, $page, 10, $filters);

        $this->assign('title', __('我的订阅'));
        $this->assign('items', $result['items']);
        $this->assign('pagination', $result['pagination']);
        $this->assign('total', $result['total']);
        $this->assign('current_status', $status);
        $this->assign('status_options', Subscription::getStatusOptions());

        return $this->fetch();
    }
}
