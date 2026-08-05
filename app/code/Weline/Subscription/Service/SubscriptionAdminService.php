<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Payment\Model\PaymentProviderCommandOutbox;
use Weline\Payment\Service\PaymentFacadeV2;
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
        $subscription = $this->subscriptions->get($subscriptionId);
        if ((string)($subscription['environment'] ?? '') !== SubscriptionState::ENV_SANDBOX) {
            throw new \RuntimeException('subscription_admin_renewal_requires_sandbox');
        }

        $paymentPort = $this->scheduler->payments();
        if (!$paymentPort instanceof PaymentFacadeSubscriptionPaymentPort
            || !($paymentPort->facade() instanceof PaymentFacadeV2)
        ) {
            throw new \RuntimeException('subscription_admin_payment_facade_unavailable');
        }
        $payments = $paymentPort->facade();
        $entryWasEnabled = $payments->isEntryEnabled();
        $payments->setEntryEnabled(true);
        try {
            $started = $this->scheduler->tick($subscriptionId, $workerId);
            $journal = $this->scheduler->attempts()->get((string)($started['attempt_id'] ?? ''));
            $attemptCode = trim((string)($journal['payment_attempt_code'] ?? ''));
            if ($attemptCode === '') {
                throw new \RuntimeException('subscription_admin_payment_attempt_missing');
            }

            $command = clone $this->providerCommands;
            $commandRows = $command->clear()->reset()
                ->where(PaymentProviderCommandOutbox::schema_fields_ATTEMPT_CODE, $attemptCode)
                ->select()->fetchArray();
            $commandRows = is_array($commandRows)
                ? array_values(array_filter($commandRows, 'is_array'))
                : [];
            if (count($commandRows) !== 1) {
                throw new \RuntimeException('subscription_admin_payment_outbox_not_unique');
            }
            $commandCode = trim((string)($commandRows[0][PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE] ?? ''));
            if ($commandCode === '') {
                throw new \RuntimeException('subscription_admin_payment_outbox_missing');
            }
            $processed = $payments->orchestrator()->processOneOutbox($commandCode);
            if (empty($processed['ok'])) {
                throw new \RuntimeException('subscription_admin_payment_provider_failed:' . (string)($processed['error_code'] ?? 'unknown'));
            }

            $reconciled = $this->scheduler->tick($subscriptionId, $workerId);
            if ((string)($reconciled['attempt_status'] ?? '') !== SubscriptionBillingAttemptStore::STATUS_SUCCEEDED
                || (string)($reconciled['payment_status'] ?? '') !== SubscriptionBillingAttemptStore::STATUS_SUCCEEDED
            ) {
                throw new \RuntimeException('subscription_admin_payment_not_succeeded');
            }
            return $reconciled + [
                'payment_attempt_code' => $attemptCode,
                'provider_command_code' => $commandCode,
            ];
        } finally {
            $payments->setEntryEnabled($entryWasEnabled);
        }
    }
}
