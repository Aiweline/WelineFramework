<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Subscription;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 后台查看订阅详情
 */
class View extends BackendController
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
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            $this->getMessageManager()->addWarning(__('缺少订阅ID'));
            $this->redirect($this->getUrl('*/subscription/index'));
            return '';
        }

        $this->subscription->load($id);

        if (!$this->subscription->getId()) {
            $this->getMessageManager()->addError(__('订阅不存在'));
            $this->redirect($this->getUrl('*/subscription/index'));
            return '';
        }

        // 加载订阅计划
        $planId = (int)$this->subscription->getData(Subscription::schema_fields_PLAN_ID);
        $this->subscriptionPlan->load($planId);

        // 获取历史记录
        $history = $this->subscriptionService->getSubscriptionHistory($id, 1, 50);

        $this->assign('title', __('订阅详情'));
        $this->assign('subscription', $this->subscription);
        $this->assign('plan', $this->subscriptionPlan);
        $this->assign('history', $history);
        $this->assign('status_options', Subscription::getStatusOptions());

        return $this->fetch();
    }

    /**
     * 后台取消订阅
     */
    public function postCancel(): string
    {
        try {
            $id = (int)$this->request->getPost('id');
            $reason = (string)$this->request->getPost('reason');

            $this->subscriptionService->cancelSubscription($id, 0, $reason);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已取消'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('取消失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 后台暂停订阅
     */
    public function postPause(): string
    {
        try {
            $id = (int)$this->request->getPost('id');

            $this->subscriptionService->pauseSubscription($id);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已暂停'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('暂停失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 后台恢复订阅
     */
    public function postResume(): string
    {
        try {
            $id = (int)$this->request->getPost('id');

            $this->subscriptionService->resumeSubscription($id);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已恢复'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('恢复失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
