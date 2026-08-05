<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Subscription\Api\SubscriptionOrderPortInterface;
use Weline\Subscription\Api\SubscriptionPaymentPortInterface;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Durable Subscription renewal orchestration.
 *
 * Ordering is a safety contract:
 * mode → lease → existing-obligation reconcile → Store guard → Subscription
 * version fence → Attempt → Order → Payment → pointer/watermark.
 */
final class SubscriptionSchedulerService
{
    public const CAPABILITY = SubscriptionService::CAPABILITY;
    public const ERROR_MODE_OFF = 'subscription_scheduler_mode_off';
    public const ERROR_CANCELLED = 'subscription_scheduler_cancelled';
    public const ERROR_LEASE = 'subscription_scheduler_lease_denied';
    public const ERROR_PAYMENT_FAILED = 'subscription_payment_failed';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionSchedulerLeaseStore $leases,
        private readonly SubscriptionBillingAttemptStore $attempts,
        private readonly SubscriptionMissedWatermarkStore $missed,
        private readonly SubscriptionOrderPortInterface $orders,
        private readonly SubscriptionPaymentPortInterface $payments,
        private readonly SubscriptionStoreEligibilityService $storeEligibility,
        private readonly SubscriptionRolloutGate $rollout,
        private readonly int $defaultAmountMinor = 1000,
    ) {
    }

    public static function forTesting(
        ?SubscriptionService $subscriptions = null,
        ?SubscriptionOrderPortInterface $orders = null,
        ?SubscriptionRolloutGate $rollout = null,
        ?SubscriptionPaymentPortInterface $payments = null,
        ?SubscriptionStoreEligibilityService $storeEligibility = null,
    ): self {
        $subscriptions ??= SubscriptionService::forTesting($rollout);
        $gate = $subscriptions->rollout();

        return new self(
            $subscriptions,
            SubscriptionSchedulerLeaseStore::forTesting(),
            SubscriptionBillingAttemptStore::forTesting(),
            SubscriptionMissedWatermarkStore::forTesting(),
            $orders ?? ArraySubscriptionOrderPort::forTesting(),
            $payments ?? ArraySubscriptionPaymentPort::forTesting(),
            $storeEligibility ?? SubscriptionStoreEligibilityService::forTesting(),
            $gate,
        );
    }

    public function subscriptions(): SubscriptionService
    {
        return $this->subscriptions;
    }

    public function leases(): SubscriptionSchedulerLeaseStore
    {
        return $this->leases;
    }

    public function attempts(): SubscriptionBillingAttemptStore
    {
        return $this->attempts;
    }

    public function missed(): SubscriptionMissedWatermarkStore
    {
        return $this->missed;
    }

    public function orders(): SubscriptionOrderPortInterface
    {
        return $this->orders;
    }

    public function payments(): SubscriptionPaymentPortInterface
    {
        return $this->payments;
    }

    public function storeEligibility(): SubscriptionStoreEligibilityService
    {
        return $this->storeEligibility;
    }

    public function rollout(): SubscriptionRolloutGate
    {
        return $this->rollout;
    }

    /** @return array<string, mixed> */
    public function tick(string $subscriptionId, string $workerId, bool $allowRecover = false): array
    {
        $subscription = $this->subscriptions->get($subscriptionId);
        $this->assertTickAllowed((int) $subscription['website_id'], $allowRecover);
        return $this->withLease(
            $subscriptionId,
            $workerId,
            fn (): array => $this->billPeriod($subscriptionId, $workerId, null),
        );
    }

    /** @return array<string, mixed> */
    public function recover(string $subscriptionId, string $workerId, int $periodIndex): array
    {
        if ($periodIndex < 1) {
            throw new \InvalidArgumentException(__('Subscription recover period_index 非法'));
        }
        // Existing missed/unknown obligation recovery remains available while mode is off.
        $this->subscriptions->get($subscriptionId);
        return $this->withLease(
            $subscriptionId,
            $workerId,
            fn (): array => $this->billPeriod($subscriptionId, $workerId, $periodIndex),
        ) + ['recovered' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function billPeriod(
        string $subscriptionId,
        string $workerId,
        ?int $requestedPeriodIndex,
    ): array {
        // Re-read only after the durable lease is held.
        $subscription = $this->assertActive($this->subscriptions->get($subscriptionId));
        $websiteId = (int) $subscription['website_id'];
        $storeId = (int) ($subscription['store_id'] ?? 0);
        $provider = $this->subscriptions->providers()->get((string) $subscription['provider_code']);
        $periodIndex = $requestedPeriodIndex ?? max(1, (int) $subscription['current_period_index']);
        $periodKey = $provider->periodKey($subscriptionId, $periodIndex);
        $period = $this->findPeriod($periodKey);

        // An existing Order/Intent is an obligation, not authorization for a new charge.
        if ($period !== null && trim((string) ($period['order_ref'] ?? '')) !== '') {
            return $this->reconcileExisting(
                $subscription,
                $period,
                $workerId,
                $periodIndex,
            );
        }

        // No external obligation exists yet: Store lifecycle and aggregate CAS must win first.
        $this->storeEligibility->assertRenewalAllowed($websiteId, $storeId);
        $subscription = $this->assertActive($this->subscriptions->get($subscriptionId));
        $claimed = $this->subscriptions->store()->replaceWithVersionBump(
            $subscriptionId,
            (int) $subscription['version'],
            [],
        );
        $this->assertActive($claimed);

        $period ??= $this->subscriptions->periods()->openPeriod([
            'subscription_id' => $subscriptionId,
            'period_index' => $periodIndex,
            'period_key' => $periodKey,
            'website_id' => $websiteId,
        ]);
        $attempt = $this->attempts->start($periodKey, $subscriptionId, $workerId);
        if (!empty($attempt['replayed'])
            && \in_array(
                (string) $attempt['status'],
                [SubscriptionBillingAttemptStore::STATUS_PENDING, SubscriptionBillingAttemptStore::STATUS_UNKNOWN],
                true,
            )
        ) {
            if (trim((string) ($attempt['order_ref'] ?? '')) !== '') {
                return $this->queryAttempt($subscription, $period, $attempt, $periodIndex);
            }
            throw new SubscriptionConflictException(
                'subscription_attempt_incomplete',
                __('Billing Attempt 尚未绑定 Order，禁止替代重试'),
                ['attempt_id' => $attempt['attempt_id'], 'period_key' => $periodKey],
            );
        }

        try {
            $created = $this->orders->createPeriodOrder($this->orderCommand(
                $subscription,
                $periodKey,
            ));
            $orderRef = trim((string) ($created['order_ref'] ?? ''));
            $attempt = $this->attempts->bindOrder((string) $attempt['attempt_id'], $orderRef);
            $period = $this->subscriptions->periods()->attachOrder($periodKey, $orderRef);

            $payment = $this->payments->startPeriodPayment($this->paymentCommand(
                $subscription,
                $periodKey,
                $orderRef,
            ));
            $attempt = $this->attempts->recordPayment((string) $attempt['attempt_id'], $payment);
            return $this->resultAfterPayment(
                $subscription,
                $period,
                $attempt,
                $periodIndex,
                !empty($created['replayed']),
            );
        } catch (Throwable $throwable) {
            $latest = $this->attempts->get((string) $attempt['attempt_id']);
            $orderRef = trim((string) ($latest['order_ref'] ?? ''));
            $code = $throwable instanceof SubscriptionConflictException
                ? $throwable->errorCode
                : 'subscription_billing_failed';
            if ($orderRef === '') {
                $this->attempts->fail((string) $attempt['attempt_id'], $code);
                $this->subscriptions->periods()->markMissed($periodKey, $code);
                $this->missed->record($subscriptionId, $periodIndex, $periodKey, $code);
            } else {
                // The Order identity already exists. Never release it as an ordinary failure.
                $this->attempts->unknown(
                    (string) $attempt['attempt_id'],
                    $code,
                    $latest['payment_intent_code'] ?? null,
                    $latest['payment_attempt_code'] ?? null,
                );
            }
            throw $throwable instanceof SubscriptionConflictException
                ? $throwable
                : new SubscriptionConflictException(
                    $code,
                    $throwable->getMessage(),
                    ['period_key' => $periodKey, 'order_ref' => $orderRef !== '' ? $orderRef : null],
                    0,
                    $throwable,
                );
        }
    }

    /** @param array<string, mixed> $subscription @param array<string, mixed> $period */
    private function reconcileExisting(
        array $subscription,
        array $period,
        string $workerId,
        int $periodIndex,
    ): array {
        $periodKey = (string) $period['period_key'];
        $attempt = $this->attempts->latestForPeriod($periodKey);
        if ($attempt === null) {
            // A pre-P4B-002 Order may already have a Payment Intent. Create one local
            // journal row and query by payable before considering any start.
            $attempt = $this->attempts->start(
                $periodKey,
                (string) $subscription['subscription_id'],
                $workerId,
            );
            $attempt = $this->attempts->bindOrder(
                (string) $attempt['attempt_id'],
                (string) $period['order_ref'],
            );
            return $this->queryAttempt($subscription, $period, $attempt, $periodIndex);
        }
        if ((string) $attempt['status'] === SubscriptionBillingAttemptStore::STATUS_SUCCEEDED) {
            $this->advanceAfterSuccess($subscription, $periodIndex);
            return $this->formatResult($subscription, $period, $attempt, true);
        }
        if ((string) $attempt['status'] === SubscriptionBillingAttemptStore::STATUS_FAILED) {
            return $this->formatResult($subscription, $period, $attempt, true) + [
                'ok' => false,
                'error_code' => $attempt['error_code'] ?? self::ERROR_PAYMENT_FAILED,
            ];
        }
        return $this->queryAttempt($subscription, $period, $attempt, $periodIndex);
    }

    /** @param array<string, mixed> $subscription @param array<string, mixed> $period @param array<string, mixed> $attempt */
    private function queryAttempt(
        array $subscription,
        array $period,
        array $attempt,
        int $periodIndex,
    ): array {
        $payment = $this->payments->queryPeriodPayment([
            'order_ref' => (string) ($attempt['order_ref'] ?? $period['order_ref'] ?? ''),
            'customer_id' => (string) $subscription['customer_id'],
            'intent_code' => $attempt['payment_intent_code'] ?? null,
        ]);
        $attempt = $this->attempts->recordPayment((string) $attempt['attempt_id'], $payment);
        return $this->resultAfterPayment($subscription, $period, $attempt, $periodIndex, true);
    }

    /** @param array<string, mixed> $subscription @param array<string, mixed> $period @param array<string, mixed> $attempt */
    private function resultAfterPayment(
        array $subscription,
        array $period,
        array $attempt,
        int $periodIndex,
        bool $replayed,
    ): array {
        if ((string) $attempt['status'] === SubscriptionBillingAttemptStore::STATUS_SUCCEEDED) {
            $this->advanceAfterSuccess($subscription, $periodIndex);
        } elseif ((string) $attempt['status'] === SubscriptionBillingAttemptStore::STATUS_FAILED) {
            $this->missed->record(
                (string) $subscription['subscription_id'],
                $periodIndex,
                (string) $period['period_key'],
                (string) ($attempt['error_code'] ?? self::ERROR_PAYMENT_FAILED),
            );
        }
        $result = $this->formatResult($subscription, $period, $attempt, $replayed);
        if ((string) $attempt['status'] === SubscriptionBillingAttemptStore::STATUS_FAILED) {
            $result['ok'] = false;
            $result['error_code'] = $attempt['error_code'] ?? self::ERROR_PAYMENT_FAILED;
        }
        return $result;
    }

    /** @param array<string, mixed> $subscription */
    private function advanceAfterSuccess(array $subscription, int $periodIndex): void
    {
        $subscriptionId = (string) $subscription['subscription_id'];
        $current = $this->subscriptions->get($subscriptionId);
        if ((string) $current['status'] !== SubscriptionState::STATUS_ACTIVE
            || (int) $current['current_period_index'] !== $periodIndex
        ) {
            return;
        }
        try {
            $nextIndex = $periodIndex + 1;
            $saved = $this->subscriptions->store()->replaceWithVersionBump(
                $subscriptionId,
                (int) $current['version'],
                ['current_period_index' => $nextIndex],
            );
            $provider = $this->subscriptions->providers()->get((string) $saved['provider_code']);
            $this->subscriptions->periods()->openPeriod([
                'subscription_id' => $subscriptionId,
                'period_index' => $nextIndex,
                'period_key' => $provider->periodKey($subscriptionId, $nextIndex),
                'website_id' => (int) $saved['website_id'],
            ]);
        } catch (SubscriptionConflictException $conflict) {
            $winner = $this->subscriptions->get($subscriptionId);
            if ((string) $winner['status'] === SubscriptionState::STATUS_CANCELLED
                || (int) $winner['current_period_index'] > $periodIndex
            ) {
                return;
            }
            throw $conflict;
        }
    }

    /** @param array<string, mixed> $subscription @return array<string, mixed> */
    private function assertActive(array $subscription): array
    {
        if ((string) $subscription['status'] !== SubscriptionState::STATUS_ACTIVE) {
            throw new SubscriptionConflictException(
                self::ERROR_CANCELLED,
                __('已取消或暂停订阅不可调度：%{1}', [$subscription['subscription_id'] ?? '']),
                ['subscription_id' => $subscription['subscription_id'] ?? null],
            );
        }
        return $subscription;
    }

    /** @return array<string, mixed>|null */
    private function findPeriod(string $periodKey): ?array
    {
        try {
            return $this->subscriptions->periods()->getByKey($periodKey);
        } catch (SubscriptionConflictException $conflict) {
            if ($conflict->errorCode === SubscriptionPeriodStore::ERROR_NOT_FOUND) {
                return null;
            }
            throw $conflict;
        }
    }

    /**
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function withLease(string $subscriptionId, string $workerId, callable $callback): array
    {
        $lease = $this->leases->acquire($subscriptionId, $workerId);
        if (empty($lease['ok'])) {
            throw new SubscriptionConflictException(
                self::ERROR_LEASE,
                __('Scheduler lease 被占用：%{1}', [$lease['worker_id'] ?? '']),
                ['subscription_id' => $subscriptionId, 'holder' => $lease['worker_id'] ?? null],
            );
        }
        try {
            return $callback();
        } finally {
            $this->leases->release($subscriptionId, $workerId, (string) ($lease['token'] ?? ''));
        }
    }

    /** @param array<string, mixed> $subscription @return array<string, mixed> */
    private function orderCommand(array $subscription, string $periodKey): array
    {
        return [
            'period_key' => $periodKey,
            'subscription_id' => (string) $subscription['subscription_id'],
            'website_id' => (int) $subscription['website_id'],
            'store_id' => (int) ($subscription['store_id'] ?? 0),
            'customer_id' => (string) $subscription['customer_id'],
            'plan_code' => (string) $subscription['plan_code'],
            'amount_minor' => $this->defaultAmountMinor,
            'currency' => 'CNY',
        ];
    }

    /** @param array<string, mixed> $subscription @return array<string, mixed> */
    private function paymentCommand(array $subscription, string $periodKey, string $orderRef): array
    {
        return [
            'period_key' => $periodKey,
            'subscription_id' => (string) $subscription['subscription_id'],
            'order_ref' => $orderRef,
            'website_id' => (int) $subscription['website_id'],
            'store_id' => (int) ($subscription['store_id'] ?? 0),
            'customer_id' => (string) $subscription['customer_id'],
            'environment' => (string) $subscription['environment'],
        ];
    }

    /** @param array<string, mixed> $subscription @param array<string, mixed> $period @param array<string, mixed> $attempt @return array<string, mixed> */
    private function formatResult(
        array $subscription,
        array $period,
        array $attempt,
        bool $replayed,
    ): array {
        return [
            'ok' => true,
            'replayed' => $replayed,
            'subscription_id' => (string) $subscription['subscription_id'],
            'period' => $period,
            'order_ref' => (string) ($attempt['order_ref'] ?? $period['order_ref'] ?? ''),
            'attempt_id' => (string) $attempt['attempt_id'],
            'attempt_status' => (string) $attempt['status'],
            'payment_status' => $attempt['payment_status'] ?? null,
            'payment_intent_code' => $attempt['payment_intent_code'] ?? null,
        ];
    }

    private function assertTickAllowed(int $websiteId, bool $allowRecover): void
    {
        SubscriptionState::assertWebsiteId($websiteId);
        $mode = $this->rollout->mode(self::CAPABILITY);
        if ($mode === SubscriptionRolloutGate::MODE_OFF) {
            if ($allowRecover) {
                return;
            }
            throw new SubscriptionConflictException(
                self::ERROR_MODE_OFF,
                __('Subscription scheduler mode off：禁止新 tick，既有义务可 recover'),
                ['capability' => self::CAPABILITY, 'website_id' => $websiteId],
            );
        }
        $this->rollout->assertMutable(self::CAPABILITY, 'website:' . $websiteId);
    }
}
