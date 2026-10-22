<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Storefront buyer surface over the durable Subscription facade.
 *
 * Customer, website and store identity are supplied by the caller from the
 * authenticated request. This service does not read raw HTTP parameters.
 */
final class SubscriptionBuyerService
{
    public const WORKER_ID = 'storefront-buyer';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionSchedulerService $scheduler,
    ) {
    }

    public static function forTesting(
        ?SubscriptionService $subscriptions = null,
        ?SubscriptionSchedulerService $scheduler = null,
    ): self {
        $subscriptions ??= SubscriptionService::forTesting();
        $scheduler ??= SubscriptionSchedulerService::forTesting($subscriptions, null, $subscriptions->rollout());

        return new self($subscriptions, $scheduler);
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    public function providers(): array
    {
        $choices = [];
        foreach ($this->subscriptions->providers()->codes() as $code) {
            $provider = $this->subscriptions->providers()->get($code);
            $choices[] = [
                'code' => $provider->getCode(),
                'label' => $provider->getLabel(),
            ];
        }

        return $choices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(string $customerId, int $websiteId): array
    {
        $rows = [];
        foreach ($this->subscriptions->store()->listForCustomer($customerId, $websiteId) as $row) {
            $rows[] = $this->present($row, false);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentOwned(string $subscriptionId, string $customerId): array
    {
        return $this->present($this->subscriptions->assertOwner($subscriptionId, $customerId), true);
    }

    /**
     * @param array{
     *   customer_id:string,
     *   website_id:int,
     *   store_id?:int,
     *   provider_code:string,
     *   plan_code:string,
     *   idempotency_key:string,
     *   environment?:string
     * } $input
     * @return array<string, mixed>
     */
    public function subscribe(array $input): array
    {
        $created = $this->subscriptions->create([
            'customer_id' => trim((string) ($input['customer_id'] ?? '')),
            'website_id' => (int) ($input['website_id'] ?? -1),
            'store_id' => (int) ($input['store_id'] ?? 0),
            'provider_code' => trim((string) ($input['provider_code'] ?? '')),
            'plan_code' => trim((string) ($input['plan_code'] ?? '')),
            'idempotency_key' => trim((string) ($input['idempotency_key'] ?? '')),
            'environment' => (string) ($input['environment'] ?? SubscriptionState::ENV_SANDBOX),
        ]);

        return [
            'success' => true,
            'replayed' => !empty($created['replayed']),
            'subscription' => $this->present($created, true),
        ];
    }

    /**
     * Bill the current due period. A later call bills the next period.
     *
     * @return array<string, mixed>
     */
    public function renew(string $subscriptionId, string $customerId): array
    {
        $owned = $this->subscriptions->assertOwner($subscriptionId, $customerId);
        if ((string) ($owned['status'] ?? '') !== SubscriptionState::STATUS_ACTIVE) {
            throw new SubscriptionConflictException(
                'subscription_buyer_not_renewable',
                __('只有生效中的订阅可以续费'),
                ['subscription_id' => $subscriptionId, 'status' => $owned['status'] ?? ''],
            );
        }
        if ((string) ($owned['environment'] ?? '') !== SubscriptionState::ENV_SANDBOX) {
            throw new SubscriptionConflictException(
                'subscription_buyer_renewal_requires_sandbox',
                __('店面续费只接受沙箱订阅'),
                ['subscription_id' => $subscriptionId],
            );
        }
        $paymentPort = $this->scheduler->payments();
        if ($paymentPort instanceof PaymentFacadeSubscriptionPaymentPort
            && $paymentPort->facade() instanceof PaymentFacadeV2
        ) {
            try {
                $result = (new SubscriptionSandboxBilling($this->scheduler))->settle($subscriptionId, self::WORKER_ID);
            } catch (\RuntimeException $exception) {
                $errorCode = explode(':', $exception->getMessage(), 2)[0];

                return [
                    'success' => false,
                    'replayed' => false,
                    'subscription_id' => $subscriptionId,
                    'attempt_status' => SubscriptionBillingAttemptStore::STATUS_FAILED,
                    'payment_status' => '',
                    'error_code' => $errorCode,
                    'message' => (string) __('续费未完成：%1', $errorCode),
                ];
            }
        } else {
            $result = $this->scheduler->tick($subscriptionId, self::WORKER_ID);
        }

        $errorCode = (string) ($result['error_code'] ?? '');

        return [
            'success' => ($result['ok'] ?? false) === true && $errorCode === '',
            'replayed' => !empty($result['replayed']),
            'subscription_id' => (string) ($result['subscription_id'] ?? $subscriptionId),
            'attempt_status' => (string) ($result['attempt_status'] ?? ''),
            'payment_status' => (string) ($result['payment_status'] ?? ''),
            'error_code' => $errorCode,
            'message' => $errorCode === '' ? '' : (string) __('续费未完成：%1', $errorCode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $subscriptionId, string $customerId, int $expectedVersion): array
    {
        $cancelled = $this->subscriptions->cancel($subscriptionId, $customerId, $expectedVersion);

        return [
            'success' => true,
            'subscription' => $this->present($cancelled, true),
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            SubscriptionState::STATUS_ACTIVE => (string) __('生效中'),
            SubscriptionState::STATUS_CANCELLED => (string) __('已取消'),
            SubscriptionState::STATUS_PAUSED => (string) __('已暂停'),
            default => $status,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row, bool $withPeriods): array
    {
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $presented = [
            'subscription_id' => $subscriptionId,
            'plan_code' => (string) ($row['plan_code'] ?? ''),
            'provider_code' => (string) ($row['provider_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'status_label' => $this->statusLabel((string) ($row['status'] ?? '')),
            'version' => (int) ($row['version'] ?? 0),
            'current_period_index' => (int) ($row['current_period_index'] ?? 0),
            'environment' => (string) ($row['environment'] ?? ''),
            'store_id' => (int) ($row['store_id'] ?? 0),
            'website_id' => (int) ($row['website_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'cancelled_at' => $row['cancelled_at'] ?? null,
        ];
        if ($withPeriods && $subscriptionId !== '') {
            $presented['periods'] = [];
            foreach ($this->subscriptions->periods()->listForSubscription($subscriptionId) as $period) {
                $presented['periods'][] = [
                    'period_index' => (int) ($period['period_index'] ?? 0),
                    'status' => (string) ($period['status'] ?? ''),
                    'period_key' => (string) ($period['period_key'] ?? ''),
                ];
            }
        }

        return $presented;
    }
}
