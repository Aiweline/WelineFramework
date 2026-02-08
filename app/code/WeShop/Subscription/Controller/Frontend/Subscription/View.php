<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 前台查看订阅详情
 */
class View extends FrontendController
{
    private Subscription $subscription;
    private SubscriptionPlan $subscriptionPlan;
    private SubscriptionService $subscriptionService;

    public function __construct(
        Subscription        $subscription,
        SubscriptionPlan    $subscriptionPlan,
        SubscriptionService $subscriptionService
    ) {
        $this->subscription = $subscription;
        $this->subscriptionPlan = $subscriptionPlan;
        $this->subscriptionService = $subscriptionService;
    }

    public function index(): string
    {
        $customerId = (int)$this->session->getLoginCustomerId();

        if (!$customerId) {
            $this->redirect($this->getUrl('customer/account/login'));
            return '';
        }

        $id = (int)$this->request->getGet('id');

        $this->subscription->load($id);

        if (!$this->subscription->getId()) {
            $this->getMessageManager()->addError(__('订阅不存在'));
            $this->redirect($this->getUrl('*/subscription/subscriptionList'));
            return '';
        }

        // 验证客户权限
        if ((int)$this->subscription->getData(Subscription::fields_CUSTOMER_ID) !== $customerId) {
            $this->getMessageManager()->addError(__('无权查看此订阅'));
            $this->redirect($this->getUrl('*/subscription/subscriptionList'));
            return '';
        }

        // 加载订阅计划
        $planId = (int)$this->subscription->getData(Subscription::fields_PLAN_ID);
        $this->subscriptionPlan->load($planId);

        // 获取历史记录
        $history = $this->subscriptionService->getSubscriptionHistory($id, 1, 20);

        $this->assign('title', __('订阅详情'));
        $this->assign('subscription', $this->subscription);
        $this->assign('plan', $this->subscriptionPlan);
        $this->assign('history', $history);

        return $this->fetch();
    }
}
