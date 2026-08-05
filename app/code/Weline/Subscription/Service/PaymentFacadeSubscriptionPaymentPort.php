<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentQueryCommand;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\PaymentFacadeV2Interface;
use Weline\Subscription\Api\SubscriptionPaymentPortInterface;

/** Production Subscription → Payment V2 adapter. */
final class PaymentFacadeSubscriptionPaymentPort implements SubscriptionPaymentPortInterface
{
    public const PAYABLE_TYPE_ORDER = 'weline_order';
    public const ERROR_RESULT_UNKNOWN = 'subscription_payment_result_unknown';

    public function __construct(
        private readonly PaymentFacadeV2Interface $payments,
        private readonly string $sandboxMethodCode = 'fake_card',
    ) {
    }

    public function facade(): PaymentFacadeV2Interface
    {
        return $this->payments;
    }

    public function startPeriodPayment(array $command): array
    {
        $periodKey = trim((string) ($command['period_key'] ?? ''));
        $subscriptionId = trim((string) ($command['subscription_id'] ?? ''));
        $orderRef = trim((string) ($command['order_ref'] ?? ''));
        $customerId = trim((string) ($command['customer_id'] ?? ''));
        $websiteId = (int) ($command['website_id'] ?? -1);
        $storeId = (int) ($command['store_id'] ?? -1);
        $environment = strtolower(trim((string) ($command['environment'] ?? 'sandbox')));
        if ($periodKey === '' || $subscriptionId === '' || $orderRef === ''
            || $websiteId < 0 || $storeId < 0
        ) {
            throw new \InvalidArgumentException(__('Subscription Payment command 非法'));
        }
        if ($environment !== 'sandbox') {
            throw new SubscriptionConflictException(
                'subscription_payment_live_not_authorized',
                __('TASK-P4B-002 只允许 sandbox Payment'),
                ['subscription_id' => $subscriptionId, 'environment' => $environment],
            );
        }
        $actor = $this->actor($customerId);
        $callerHash = hash('sha256', implode('|', [
            $periodKey,
            $subscriptionId,
            $orderRef,
            (string) $websiteId,
            (string) $storeId,
        ]));
        try {
            $result = $this->payments->start(PaymentStartCommand::create(
                payableType: self::PAYABLE_TYPE_ORDER,
                payableId: $orderRef,
                methodCode: $this->sandboxMethodCode,
                idempotencyKey: 'subscription-payment-' . hash('sha256', $periodKey),
                requestHash: $callerHash,
                actor: $actor,
                websiteId: $websiteId,
                storeId: $storeId,
            ));
            return $this->normalize($result);
        } catch (Throwable) {
            return [
                'status' => SubscriptionBillingAttemptStore::STATUS_UNKNOWN,
                'terminal' => false,
                'intent_code' => null,
                'payment_attempt_code' => null,
                'error_code' => self::ERROR_RESULT_UNKNOWN,
            ];
        }
    }

    public function queryPeriodPayment(array $command): array
    {
        $orderRef = trim((string) ($command['order_ref'] ?? ''));
        $intentCode = trim((string) ($command['intent_code'] ?? ''));
        if ($orderRef === '' && $intentCode === '') {
            throw new \InvalidArgumentException(__('Subscription Payment query identity 非法'));
        }
        try {
            $query = $intentCode !== ''
                ? PaymentQueryCommand::byIntent($intentCode, $this->actor((string) ($command['customer_id'] ?? '')))
                : PaymentQueryCommand::byPayable(
                    self::PAYABLE_TYPE_ORDER,
                    $orderRef,
                    $this->actor((string) ($command['customer_id'] ?? '')),
                );
            return $this->normalize($this->payments->query($query)) + ['replayed' => true];
        } catch (Throwable) {
            return [
                'status' => SubscriptionBillingAttemptStore::STATUS_UNKNOWN,
                'terminal' => false,
                'intent_code' => $intentCode !== '' ? $intentCode : null,
                'payment_attempt_code' => null,
                'error_code' => self::ERROR_RESULT_UNKNOWN,
                'replayed' => true,
            ];
        }
    }

    /** @return array{status:string,terminal:bool,intent_code:?string,payment_attempt_code:?string,error_code:?string} */
    private function normalize(PaymentOperationResult $result): array
    {
        return [
            'status' => strtolower(trim($result->getStatus())),
            'terminal' => $result->isTerminal(),
            'intent_code' => $result->getIntentCode(),
            'payment_attempt_code' => $result->getAttemptCode(),
            'error_code' => $result->getErrorCode(),
        ];
    }

    private function actor(string $customerId): Actor
    {
        $customerId = trim($customerId);
        return Actor::fromArray([
            Actor::FIELD_ACTOR_TYPE => $customerId !== '' ? 'customer' : 'guest',
            Actor::FIELD_ACTOR_ID => $customerId !== '' ? $customerId : 'anonymous',
        ]);
    }
}
