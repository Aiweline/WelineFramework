<?php

declare(strict_types=1);

namespace WeShop\Subscription\Service;

use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionPlan;
use Weline\Framework\Http\Url;

class SubscriptionListPageDataService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly Url $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $page, int $pageSize, string $status = ''): array
    {
        $filters = [];
        if ($status !== '') {
            $filters['status'] = $status;
        }

        $result = $this->subscriptionService->getCustomerSubscriptions($customerId, $page, $pageSize, $filters);
        $items = array_values(array_filter((array) ($result['items'] ?? []), 'is_array'));
        $total = (int) ($result['total'] ?? 0);
        $statusOptions = Subscription::getStatusOptions();
        $cycleOptions = SubscriptionPlan::getBillingCycleOptions();
        $pageCount = max(1, (int) ceil($total / max(1, $pageSize)));

        return [
            'items' => array_map(
                fn(array $item): array => $this->normalizeItem($item, $statusOptions, $cycleOptions),
                $items
            ),
            'status_options' => $statusOptions,
            'current_status' => $status,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'has_previous' => $page > 1,
            'has_next' => $page < $pageCount,
            'pagination' => (array) ($result['pagination'] ?? []),
            'total' => $total,
            'list_url' => $this->url->getUrl('subscription'),
            'view_url' => $this->url->getUrl('subscription/view'),
            'pause_url' => $this->url->getUrl('subscription/pause'),
            'resume_url' => $this->url->getUrl('subscription/pause/resume'),
            'cancel_url' => $this->url->getUrl('subscription/cancel'),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $statusOptions
     * @param array<string, string> $cycleOptions
     * @return array<string, mixed>
     */
    protected function normalizeItem(array $item, array $statusOptions, array $cycleOptions): array
    {
        $status = (string) ($item[Subscription::schema_fields_STATUS] ?? '');
        $interval = max(1, (int) ($item[Subscription::schema_fields_BILLING_INTERVAL] ?? 1));
        $cycle = (string) ($item[Subscription::schema_fields_BILLING_CYCLE] ?? '');
        $cycleLabel = (string) ($cycleOptions[$cycle] ?? $cycle);

        return [
            'subscription_id' => (int) ($item[Subscription::schema_fields_ID] ?? 0),
            'status' => $status,
            'status_label' => (string) ($statusOptions[$status] ?? $status),
            'price' => (float) ($item[Subscription::schema_fields_PRICE] ?? 0),
            'currency' => (string) ($item[Subscription::schema_fields_CURRENCY] ?? 'USD'),
            'billing_cycle' => $cycle,
            'billing_interval' => $interval,
            'billing_cycle_label' => $cycleLabel,
            'billing_label' => $interval > 1
                ? (string) __('Every %1 %2', $interval, $cycleLabel)
                : (string) __('Every %1', $cycleLabel),
            'current_period_start' => (string) ($item[Subscription::schema_fields_CURRENT_PERIOD_START] ?? ''),
            'current_period_end' => (string) ($item[Subscription::schema_fields_CURRENT_PERIOD_END] ?? ''),
            'next_billing_at' => (string) ($item[Subscription::schema_fields_NEXT_BILLING_AT] ?? ''),
            'renewal_count' => (int) ($item[Subscription::schema_fields_RENEWAL_COUNT] ?? 0),
            'created_at' => (string) ($item[Subscription::schema_fields_CREATED_AT] ?? ''),
            'can_pause' => in_array($status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true),
            'can_resume' => $status === Subscription::STATUS_PAUSED,
            'can_cancel' => in_array(
                $status,
                [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAUSED, Subscription::STATUS_PAST_DUE],
                true
            ),
        ];
    }
}
