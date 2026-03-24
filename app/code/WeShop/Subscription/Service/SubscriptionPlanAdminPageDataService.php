<?php

declare(strict_types=1);

namespace WeShop\Subscription\Service;

use WeShop\Subscription\Model\SubscriptionPlan;

class SubscriptionPlanAdminPageDataService
{
    public function __construct(
        private readonly SubscriptionPlan $subscriptionPlan
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20): array
    {
        $query = $this->subscriptionPlan->clear()
            ->order(SubscriptionPlan::schema_fields_SORT_ORDER, 'ASC')
            ->pagination($page, $pageSize);

        return [
            'items' => array_values(array_filter((array) $query->select()->fetchArray(), 'is_array')),
            'pagination' => $query->getPagination(),
            'total' => (int) $query->getTotalCount(),
            'billing_cycles' => SubscriptionPlan::getBillingCycleOptions(),
        ];
    }

    public function getEditData(int $planId = 0): array
    {
        $plan = null;
        if ($planId > 0) {
            $this->subscriptionPlan->clear()->load($planId);
            if ($this->subscriptionPlan->getId()) {
                $plan = $this->subscriptionPlan;
            }
        }

        return [
            'plan' => $plan,
            'billing_cycles' => SubscriptionPlan::getBillingCycleOptions(),
        ];
    }
}
