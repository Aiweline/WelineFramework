<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Plan;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Subscription\Model\SubscriptionPlan;

/**
 * @DESC | 后台订阅计划列表
 */
class Index extends BackendController
{
    private SubscriptionPlan $subscriptionPlan;

    public function __construct(SubscriptionPlan $subscriptionPlan)
    {
        $this->subscriptionPlan = $subscriptionPlan;
    }

    public function index(): string
    {
        $page = (int)($this->request->getGet('page') ?: 1);
        $pageSize = (int)($this->request->getGet('page_size') ?: 20);

        $this->subscriptionPlan->clear()
            ->order(SubscriptionPlan::fields_SORT_ORDER, 'ASC')
            ->pagination($page, $pageSize);

        $items = $this->subscriptionPlan->select()->fetchArray();

        $this->assign('title', __('订阅计划管理'));
        $this->assign('items', $items);
        $this->assign('pagination', $this->subscriptionPlan->getPagination());
        $this->assign('total', $this->subscriptionPlan->getTotalCount());
        $this->assign('billing_cycles', SubscriptionPlan::getBillingCycleOptions());

        return $this->fetch();
    }

    /**
     * 编辑/新建计划表单
     */
    public function getEdit(): string
    {
        $id = (int)$this->request->getGet('id');

        $plan = null;
        if ($id) {
            $this->subscriptionPlan->load($id);
            if ($this->subscriptionPlan->getId()) {
                $plan = $this->subscriptionPlan;
            }
        }

        $this->assign('title', $plan ? __('编辑订阅计划') : __('新建订阅计划'));
        $this->assign('plan', $plan);
        $this->assign('billing_cycles', SubscriptionPlan::getBillingCycleOptions());

        return $this->fetch('edit');
    }

    /**
     * 保存订阅计划
     */
    public function postSave(): string
    {
        try {
            $data = $this->request->getPost();
            $id = (int)($data['plan_id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            if ($id) {
                // 编辑
                $this->subscriptionPlan->load($id);
                if (!$this->subscriptionPlan->getId()) {
                    throw new \Exception(__('计划不存在'));
                }
            } else {
                // 新建
                $this->subscriptionPlan->clearData();
                $this->subscriptionPlan->setData(SubscriptionPlan::fields_CREATED_AT, $now);
            }

            $this->subscriptionPlan
                ->setData(SubscriptionPlan::fields_NAME, $data['name'] ?? '')
                ->setData(SubscriptionPlan::fields_DESCRIPTION, $data['description'] ?? '')
                ->setData(SubscriptionPlan::fields_PRODUCT_ID, (int)($data['product_id'] ?? 0))
                ->setData(SubscriptionPlan::fields_PRICE, (float)($data['price'] ?? 0))
                ->setData(SubscriptionPlan::fields_ORIGINAL_PRICE, (float)($data['original_price'] ?? 0))
                ->setData(SubscriptionPlan::fields_BILLING_CYCLE, $data['billing_cycle'] ?? 'month')
                ->setData(SubscriptionPlan::fields_BILLING_INTERVAL, (int)($data['billing_interval'] ?? 1))
                ->setData(SubscriptionPlan::fields_TRIAL_DAYS, (int)($data['trial_days'] ?? 0))
                ->setData(SubscriptionPlan::fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
                ->setData(SubscriptionPlan::fields_STATUS, (int)($data['status'] ?? 1))
                ->setData(SubscriptionPlan::fields_UPDATED_AT, $now)
                ->save();

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('保存成功'),
                'data' => ['id' => $this->subscriptionPlan->getId()],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('保存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除订阅计划
     */
    public function postDelete(): string
    {
        try {
            $id = (int)$this->request->getPost('id');

            $this->subscriptionPlan->reset()
                ->where(SubscriptionPlan::fields_ID, $id)
                ->delete()
                ->fetch();

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('删除失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
