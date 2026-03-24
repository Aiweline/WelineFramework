<?php

declare(strict_types=1);

namespace WeShop\Subscription\Service;

use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionHistory;
use WeShop\Subscription\Model\SubscriptionPlan;

class SubscriptionDetailPageDataService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly SubscriptionPlan $subscriptionPlan,
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $subscriptionId): array
    {
        $subscription = $this->subscription->clear();
        $subscription->load($subscriptionId);

        if (!$subscription->getId()) {
            throw new \RuntimeException((string) __('Subscription does not exist.'));
        }

        if ((int) $subscription->getData(Subscription::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \RuntimeException((string) __('You do not have permission to view this subscription.'));
        }

        $plan = $this->subscriptionPlan->clear();
        $plan->load((int) $subscription->getData(Subscription::schema_fields_PLAN_ID));

        $history = $this->subscriptionService->getSubscriptionHistory((int) $subscription->getId(), 1, 20);
        $historyItems = array_values(array_filter((array) ($history['items'] ?? []), 'is_array'));

        return [
            'subscription' => $this->normalizeSubscription($subscription->getData()),
            'plan' => $plan->getId() ? $this->normalizePlan($plan->getData()) : [],
            'history' => array_map([$this, 'normalizeHistoryItem'], $historyItems),
            'history_total' => (int) ($history['total'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    protected function normalizeSubscription(array $subscription): array
    {
        $status = (string) ($subscription[Subscription::schema_fields_STATUS] ?? '');
        $interval = max(1, (int) ($subscription[Subscription::schema_fields_BILLING_INTERVAL] ?? 1));
        $cycle = (string) ($subscription[Subscription::schema_fields_BILLING_CYCLE] ?? '');
        $cycleOptions = SubscriptionPlan::getBillingCycleOptions();
        $cycleLabel = (string) ($cycleOptions[$cycle] ?? $cycle);

        return [
            'subscription_id' => (int) ($subscription[Subscription::schema_fields_ID] ?? 0),
            'status' => $status,
            'status_label' => (string) (Subscription::getStatusOptions()[$status] ?? $status),
            'price' => (float) ($subscription[Subscription::schema_fields_PRICE] ?? 0),
            'currency' => (string) ($subscription[Subscription::schema_fields_CURRENCY] ?? 'USD'),
            'billing_label' => $interval > 1
                ? (string) __('Every %1 %2', $interval, $cycleLabel)
                : (string) __('Every %1', $cycleLabel),
            'current_period_start' => (string) ($subscription[Subscription::schema_fields_CURRENT_PERIOD_START] ?? ''),
            'current_period_end' => (string) ($subscription[Subscription::schema_fields_CURRENT_PERIOD_END] ?? ''),
            'next_billing_at' => (string) ($subscription[Subscription::schema_fields_NEXT_BILLING_AT] ?? ''),
            'trial_ends_at' => (string) ($subscription[Subscription::schema_fields_TRIAL_ENDS_AT] ?? ''),
            'renewal_count' => (int) ($subscription[Subscription::schema_fields_RENEWAL_COUNT] ?? 0),
            'created_at' => (string) ($subscription[Subscription::schema_fields_CREATED_AT] ?? ''),
            'can_pause' => in_array($status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true),
            'can_resume' => $status === Subscription::STATUS_PAUSED,
            'can_cancel' => in_array(
                $status,
                [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAUSED, Subscription::STATUS_PAST_DUE],
                true
            ),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    protected function normalizePlan(array $plan): array
    {
        return [
            'plan_id' => (int) ($plan[SubscriptionPlan::schema_fields_ID] ?? 0),
            'name' => (string) ($plan[SubscriptionPlan::schema_fields_NAME] ?? ''),
            'description' => (string) ($plan[SubscriptionPlan::schema_fields_DESCRIPTION] ?? ''),
            'trial_days' => (int) ($plan[SubscriptionPlan::schema_fields_TRIAL_DAYS] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function normalizeHistoryItem(array $item): array
    {
        $action = (string) ($item[SubscriptionHistory::schema_fields_ACTION] ?? '');
        $actionOptions = SubscriptionHistory::getActionOptions();

        return [
            'history_id' => (int) ($item[SubscriptionHistory::schema_fields_ID] ?? 0),
            'action' => $action,
            'action_label' => (string) ($actionOptions[$action] ?? $action),
            'amount' => (float) ($item[SubscriptionHistory::schema_fields_AMOUNT] ?? 0),
            'order_id' => (int) ($item[SubscriptionHistory::schema_fields_ORDER_ID] ?? 0),
            'note' => (string) ($item[SubscriptionHistory::schema_fields_NOTE] ?? ''),
            'operator' => (string) ($item[SubscriptionHistory::schema_fields_OPERATOR] ?? ''),
            'created_at' => (string) ($item[SubscriptionHistory::schema_fields_CREATED_AT] ?? ''),
        ];
    }
}
