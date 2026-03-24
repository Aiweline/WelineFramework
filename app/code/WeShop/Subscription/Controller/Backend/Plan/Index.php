<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Plan;

use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionPlanAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly SubscriptionPlan $subscriptionPlan,
        private readonly SubscriptionPlanAdminPageDataService $subscriptionPlanAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));

        $this->assign(array_merge(
            [
                'title' => (string) __('Subscription Plan Management'),
            ],
            $this->subscriptionPlanAdminPageDataService->getListData($page, $pageSize)
        ));

        return (string) $this->fetchBase('WeShop_Subscription::templates/Backend/Plan/Index/index.phtml');
    }

    public function getEdit(): string
    {
        $id = (int) $this->request->getParam('id', 0);
        $pageData = $this->subscriptionPlanAdminPageDataService->getEditData($id);
        $plan = $pageData['plan'] ?? null;

        $this->assign(array_merge(
            [
                'title' => $plan ? __('Edit Subscription Plan') : __('Create Subscription Plan'),
            ],
            $pageData
        ));

        return (string) $this->fetchBase('WeShop_Subscription::templates/Backend/Plan/Index/edit.phtml');
    }

    public function postSave(): string
    {
        try {
            $data = $this->request->getPost();
            $id = (int) ($data['plan_id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            if ($id) {
                $this->subscriptionPlan->load($id);
                if (!$this->subscriptionPlan->getId()) {
                    throw new \Exception(__('Subscription plan does not exist.'));
                }
            } else {
                $this->subscriptionPlan->clearData();
                $this->subscriptionPlan->setData(SubscriptionPlan::schema_fields_CREATED_AT, $now);
            }

            $this->subscriptionPlan
                ->setData(SubscriptionPlan::schema_fields_NAME, $data['name'] ?? '')
                ->setData(SubscriptionPlan::schema_fields_DESCRIPTION, $data['description'] ?? '')
                ->setData(SubscriptionPlan::schema_fields_PRODUCT_ID, (int) ($data['product_id'] ?? 0))
                ->setData(SubscriptionPlan::schema_fields_PRICE, (float) ($data['price'] ?? 0))
                ->setData(SubscriptionPlan::schema_fields_ORIGINAL_PRICE, (float) ($data['original_price'] ?? 0))
                ->setData(SubscriptionPlan::schema_fields_BILLING_CYCLE, $data['billing_cycle'] ?? SubscriptionPlan::CYCLE_MONTH)
                ->setData(SubscriptionPlan::schema_fields_BILLING_INTERVAL, (int) ($data['billing_interval'] ?? 1))
                ->setData(SubscriptionPlan::schema_fields_TRIAL_DAYS, (int) ($data['trial_days'] ?? 0))
                ->setData(SubscriptionPlan::schema_fields_SORT_ORDER, (int) ($data['sort_order'] ?? 0))
                ->setData(SubscriptionPlan::schema_fields_STATUS, (int) ($data['status'] ?? SubscriptionPlan::STATUS_ENABLED))
                ->setData(SubscriptionPlan::schema_fields_UPDATED_AT, $now)
                ->save();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Saved successfully.'),
                'data' => ['id' => $this->subscriptionPlan->getId()],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('Save failed: %{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    public function postDelete(): string
    {
        try {
            $id = (int) $this->request->getPost('id');

            $this->subscriptionPlan->reset()
                ->where(SubscriptionPlan::schema_fields_ID, $id)
                ->delete()
                ->fetch();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Deleted successfully.'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('Delete failed: %{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
