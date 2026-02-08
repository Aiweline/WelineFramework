<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Subscription;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 后台订阅列表
 */
class Index extends BackendController
{
    private Subscription $subscription;
    private SubscriptionService $subscriptionService;

    public function __construct(
        Subscription        $subscription,
        SubscriptionService $subscriptionService
    ) {
        $this->subscription = $subscription;
        $this->subscriptionService = $subscriptionService;
    }

    public function index(): string
    {
        $page = (int)($this->request->getGet('page') ?: 1);
        $pageSize = (int)($this->request->getGet('page_size') ?: 20);
        $status = $this->request->getGet('status');

        $query = $this->subscription->clear();

        if ($status) {
            $query->where(Subscription::fields_STATUS, $status);
        }

        $query->order(Subscription::fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $items = $query->select()->fetchArray();

        $this->assign('title', __('订阅管理'));
        $this->assign('items', $items);
        $this->assign('pagination', $query->getPagination());
        $this->assign('total', $query->getTotalCount());
        $this->assign('current_status', $status);
        $this->assign('status_options', Subscription::getStatusOptions());
        $this->assign('statistics', $this->subscriptionService->getStatistics());

        return $this->fetch();
    }
}
