<?php

declare(strict_types=1);

namespace WeShop\Subscription\Service;

use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionPlan;

class SubscriptionAdminPageDataService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly SubscriptionPlan $subscriptionPlan,
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));

        $query = $this->subscription->clear();
        if ($status !== '' && isset(Subscription::getStatusOptions()[$status])) {
            $query->where(Subscription::schema_fields_STATUS, $status);
        } else {
            $status = '';
        }

        $query->order(Subscription::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => array_values(array_filter((array) $query->select()->fetchArray(), 'is_array')),
            'pagination' => $query->getPagination(),
            'total' => (int) $query->getTotalCount(),
            'current_status' => $status,
            'status_options' => Subscription::getStatusOptions(),
            'statistics' => $this->subscriptionService->getStatistics(),
        ];
    }

    public function getDetailData(int $subscriptionId): array
    {
        $this->subscription->clear()->load($subscriptionId);
        if (!$this->subscription->getId()) {
            throw new \InvalidArgumentException((string) __('订阅不存在。'));
        }

        $planId = (int) ($this->subscription->getData(Subscription::schema_fields_PLAN_ID) ?? 0);
        $plan = $this->subscriptionPlan->clear();
        if ($planId > 0) {
            $plan->load($planId);
        }

        return [
            'subscription' => $this->subscription,
            'plan' => $plan,
            'history' => $this->subscriptionService->getSubscriptionHistory($subscriptionId, 1, 50),
            'status_options' => Subscription::getStatusOptions(),
        ];
    }
}
