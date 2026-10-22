<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentProviderCommandOutbox;
use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Sandbox-only renewal settlement.
 *
 * Storefront buyers and the backend command share this path. The payment
 * entry stays closed for ordinary checkout; it is opened only around this
 * sandbox settlement and restored before the method returns.
 */
final class SubscriptionSandboxBilling
{
    public function __construct(
        private readonly SubscriptionSchedulerService $scheduler,
        private ?PaymentProviderCommandOutbox $providerCommands = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function settle(string $subscriptionId, string $workerId): array
    {
        $subscription = $this->scheduler->subscriptions()->get($subscriptionId);
        if ((string) ($subscription['environment'] ?? '') !== SubscriptionState::ENV_SANDBOX) {
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
            $journal = $this->scheduler->attempts()->get((string) ($started['attempt_id'] ?? ''));
            $attemptCode = trim((string) ($journal['payment_attempt_code'] ?? ''));
            if ($attemptCode === '') {
                throw new \RuntimeException('subscription_admin_payment_attempt_missing');
            }

            $command = clone $this->outbox();
            $commandRows = $command->clear()->reset()
                ->where(PaymentProviderCommandOutbox::schema_fields_ATTEMPT_CODE, $attemptCode)
                ->select()->fetchArray();
            $commandRows = is_array($commandRows)
                ? array_values(array_filter($commandRows, 'is_array'))
                : [];
            if (count($commandRows) !== 1) {
                throw new \RuntimeException('subscription_admin_payment_outbox_not_unique');
            }
            $commandCode = trim((string) ($commandRows[0][PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE] ?? ''));
            if ($commandCode === '') {
                throw new \RuntimeException('subscription_admin_payment_outbox_missing');
            }
            $processed = $payments->orchestrator()->processOneOutbox($commandCode);
            if (empty($processed['ok'])) {
                throw new \RuntimeException('subscription_admin_payment_provider_failed:' . (string) ($processed['error_code'] ?? 'unknown'));
            }

            $reconciled = $this->scheduler->tick($subscriptionId, $workerId);
            if ((string) ($reconciled['attempt_status'] ?? '') !== SubscriptionBillingAttemptStore::STATUS_SUCCEEDED
                || (string) ($reconciled['payment_status'] ?? '') !== SubscriptionBillingAttemptStore::STATUS_SUCCEEDED
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

    private function outbox(): PaymentProviderCommandOutbox
    {
        return $this->providerCommands ??= ObjectManager::getInstance(PaymentProviderCommandOutbox::class);
    }
}
