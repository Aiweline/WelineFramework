<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Payment\Model\PaymentProviderCommandOutbox;
use Weline\Subscription\Model\SubscriptionState;

/** Backend command facade over the durable Subscription services. */
final class SubscriptionAdminService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionSchedulerService $scheduler,
        private readonly PaymentProviderCommandOutbox $providerCommands,
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input): array
    {
        return $this->subscriptions->create([
            'subscription_id' => trim((string)($input['subscription_id'] ?? '')),
            'customer_id' => trim((string)($input['customer_id'] ?? '')),
            'website_id' => (int)($input['website_id'] ?? -1),
            'store_id' => (int)($input['store_id'] ?? 0),
            'provider_code' => trim((string)($input['provider_code'] ?? 'interval_monthly')),
            'plan_code' => trim((string)($input['plan_code'] ?? '')),
            'idempotency_key' => trim((string)($input['idempotency_key'] ?? '')),
            'environment' => strtolower(trim((string)($input['environment'] ?? SubscriptionState::ENV_SANDBOX))),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function renew(array $input): array
    {
        $subscriptionId = trim((string)($input['subscription_id'] ?? ''));
        $workerId = trim((string)($input['worker_id'] ?? 'backend-admin'));
        return (new SubscriptionSandboxBilling($this->scheduler, $this->providerCommands))
            ->settle($subscriptionId, $workerId);
    }
}
