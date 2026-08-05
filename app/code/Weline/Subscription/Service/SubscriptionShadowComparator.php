<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Model\SubscriptionState;

/**
 * Stable conservation report over durable Subscription migration facts.
 */
final class SubscriptionShadowComparator
{
    public function __construct(
        private readonly ?SubscriptionProviderRegistry $providers = null,
    ) {
    }

    /**
     * @param array{
     *   subscriptions:list<array<string,mixed>>,
     *   periods:list<array<string,mixed>>,
     *   attempts:list<array<string,mixed>>,
     *   watermarks:list<array<string,mixed>>,
     *   leases:list<array<string,mixed>>
     * } $rows
     * @return array<string,mixed>
     */
    public function compare(array $rows, ?int $now = null): array
    {
        $now ??= time();
        $diffs = [];
        $subscriptions = [];
        foreach ($rows['subscriptions'] as $row) {
            $subscriptionId = (string) ($row['subscription_id'] ?? '');
            if ($subscriptionId === '' || isset($subscriptions[$subscriptionId])) {
                $diffs[] = [
                    'code' => 'subscription_identity_duplicate_or_empty',
                    'subscription_id' => $subscriptionId,
                ];
                continue;
            }
            $subscriptions[$subscriptionId] = $row;
        }

        $periods = [];
        $periodIndexes = [];
        $orders = [];
        $missedMax = [];
        foreach ($rows['periods'] as $row) {
            $periodKey = (string) ($row['period_key'] ?? '');
            $subscriptionId = (string) ($row['subscription_id'] ?? '');
            $periodIndex = (int) ($row['period_index'] ?? 0);
            $indexKey = $subscriptionId . '#' . $periodIndex;
            if ($periodKey === '' || isset($periods[$periodKey])) {
                $diffs[] = ['code' => 'period_key_duplicate_or_empty', 'period_key' => $periodKey];
                continue;
            }
            if (isset($periodIndexes[$indexKey])) {
                $diffs[] = [
                    'code' => 'period_index_duplicate',
                    'subscription_id' => $subscriptionId,
                    'period_index' => $periodIndex,
                ];
            }
            $periods[$periodKey] = $row;
            $periodIndexes[$indexKey] = $periodKey;

            $subscription = $subscriptions[$subscriptionId] ?? null;
            if ($subscription === null) {
                $diffs[] = [
                    'code' => 'period_subscription_missing',
                    'period_key' => $periodKey,
                ];
                continue;
            }
            try {
                $canonical = $this->registry()
                    ->get((string) $subscription['provider_code'])
                    ->periodKey($subscriptionId, $periodIndex);
                if (!hash_equals($canonical, $periodKey)) {
                    $diffs[] = [
                        'code' => 'period_key_not_canonical',
                        'period_key' => $periodKey,
                        'expected' => $canonical,
                    ];
                }
            } catch (\Throwable $exception) {
                $diffs[] = [
                    'code' => 'period_provider_invalid',
                    'period_key' => $periodKey,
                    'error' => $exception->getMessage(),
                ];
            }

            $status = (string) ($row['status'] ?? '');
            $orderRef = trim((string) ($row['order_ref'] ?? ''));
            if ($status === SubscriptionState::PERIOD_BILLED && $orderRef === '') {
                $diffs[] = ['code' => 'billed_period_missing_order', 'period_key' => $periodKey];
            }
            if ($status !== SubscriptionState::PERIOD_BILLED && $orderRef !== '') {
                $diffs[] = [
                    'code' => 'non_billed_period_has_order',
                    'period_key' => $periodKey,
                ];
            }
            if ($orderRef !== '') {
                if (isset($orders[$orderRef])) {
                    $diffs[] = [
                        'code' => 'order_ref_reused_across_periods',
                        'order_ref' => $orderRef,
                        'period_key' => $periodKey,
                    ];
                }
                $orders[$orderRef] = $periodKey;
            }
            if ($status === SubscriptionState::PERIOD_MISSED) {
                $missedMax[$subscriptionId] = max(
                    (int) ($missedMax[$subscriptionId] ?? 0),
                    $periodIndex,
                );
            }
        }

        foreach ($subscriptions as $subscriptionId => $subscription) {
            $current = max(0, (int) ($subscription['current_period_index'] ?? 0));
            // current_period_index is the next due slot. Historical indices
            // before it must be continuous; the current slot may be opened by
            // the next scheduler tick.
            for ($index = 1; $index < $current; $index++) {
                if (!isset($periodIndexes[$subscriptionId . '#' . $index])) {
                    $diffs[] = [
                        'code' => 'subscription_period_gap',
                        'subscription_id' => $subscriptionId,
                        'period_index' => $index,
                    ];
                }
            }
        }

        $activeAttempts = [];
        foreach ($rows['attempts'] as $attempt) {
            $periodKey = (string) ($attempt['period_key'] ?? '');
            $period = $periods[$periodKey] ?? null;
            if ($period === null) {
                $diffs[] = [
                    'code' => 'attempt_period_missing',
                    'attempt_id' => (string) ($attempt['attempt_id'] ?? ''),
                ];
                continue;
            }
            if ((string) ($attempt['subscription_id'] ?? '')
                !== (string) ($period['subscription_id'] ?? '')
            ) {
                $diffs[] = [
                    'code' => 'attempt_subscription_mismatch',
                    'attempt_id' => (string) ($attempt['attempt_id'] ?? ''),
                ];
            }
            $status = (string) ($attempt['status'] ?? '');
            $active = in_array($status, [
                SubscriptionBillingAttemptStore::STATUS_PENDING,
                SubscriptionBillingAttemptStore::STATUS_UNKNOWN,
            ], true);
            if ($active) {
                $activeAttempts[$periodKey] = (int) ($activeAttempts[$periodKey] ?? 0) + 1;
            }
            $attemptOrder = trim((string) ($attempt['order_ref'] ?? ''));
            $periodOrder = trim((string) ($period['order_ref'] ?? ''));
            if ($attemptOrder !== '' && $periodOrder !== '' && !hash_equals($attemptOrder, $periodOrder)) {
                $diffs[] = [
                    'code' => 'attempt_order_mismatch',
                    'attempt_id' => (string) ($attempt['attempt_id'] ?? ''),
                ];
            }
            if ($status === SubscriptionBillingAttemptStore::STATUS_UNKNOWN
                && ($attemptOrder === ''
                    || trim((string) ($attempt['payment_intent_code'] ?? '')) === '')
            ) {
                $diffs[] = [
                    'code' => 'unknown_attempt_missing_obligation_identity',
                    'attempt_id' => (string) ($attempt['attempt_id'] ?? ''),
                ];
            }
            if ($status === SubscriptionBillingAttemptStore::STATUS_SUCCEEDED
                && $attemptOrder === ''
            ) {
                $diffs[] = [
                    'code' => 'succeeded_attempt_missing_order',
                    'attempt_id' => (string) ($attempt['attempt_id'] ?? ''),
                ];
            }
        }
        foreach ($activeAttempts as $periodKey => $count) {
            if ($count > 1) {
                $diffs[] = [
                    'code' => 'multiple_active_attempts',
                    'period_key' => $periodKey,
                    'count' => $count,
                ];
            }
        }

        $watermarks = [];
        foreach ($rows['watermarks'] as $row) {
            $subscriptionId = (string) ($row['subscription_id'] ?? '');
            $watermark = (int) ($row['period_index'] ?? 0);
            if (isset($watermarks[$subscriptionId])) {
                $diffs[] = [
                    'code' => 'watermark_duplicate',
                    'subscription_id' => $subscriptionId,
                ];
            }
            $watermarks[$subscriptionId] = $watermark;
            $current = (int) ($subscriptions[$subscriptionId]['current_period_index'] ?? 0);
            if (!isset($subscriptions[$subscriptionId]) || $watermark < 1 || $watermark > $current) {
                $diffs[] = [
                    'code' => 'watermark_out_of_range',
                    'subscription_id' => $subscriptionId,
                    'watermark' => $watermark,
                    'current_period_index' => $current,
                ];
            }
        }
        foreach ($missedMax as $subscriptionId => $highestMissed) {
            if ((int) ($watermarks[$subscriptionId] ?? 0) < $highestMissed) {
                $diffs[] = [
                    'code' => 'missed_watermark_not_covered',
                    'subscription_id' => $subscriptionId,
                    'expected_minimum' => $highestMissed,
                    'actual' => (int) ($watermarks[$subscriptionId] ?? 0),
                ];
            }
        }

        $activeLeaseCount = 0;
        foreach ($rows['leases'] as $lease) {
            if ((int) ($lease['expires_at_epoch'] ?? 0) > $now) {
                $activeLeaseCount++;
                $diffs[] = [
                    'code' => 'active_scheduler_lease',
                    'subscription_id' => (string) ($lease['subscription_id'] ?? ''),
                    'worker_id' => (string) ($lease['worker_id'] ?? ''),
                ];
            }
        }

        $payload = [
            'ok' => $diffs === [],
            'subscription_count' => count($subscriptions),
            'period_count' => count($periods),
            'attempt_count' => count($rows['attempts']),
            'watermark_count' => count($rows['watermarks']),
            'billed_period_count' => count($orders),
            'unique_order_count' => count($orders),
            'active_attempt_count' => array_sum($activeAttempts),
            'active_lease_count' => $activeLeaseCount,
            'unclassified_diff_count' => count($diffs),
            'diffs' => $diffs,
            'conserved' => $diffs === [],
        ];
        $payload['report_hash'] = hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $payload;
    }

    private function registry(): SubscriptionProviderRegistry
    {
        return $this->providers ?? new SubscriptionProviderRegistry();
    }
}
