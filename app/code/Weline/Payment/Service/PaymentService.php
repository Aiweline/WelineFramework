<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\QueryRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentTransaction;

class PaymentService
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager,
        private readonly ?PayablePaymentEligibilityService $eligibilityService = null
    ) {
    }

    /**
     * @param array<string, mixed> $orderData
     */
    public function createPayment(string $methodCode, array $orderData): PaymentTransaction
    {
        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod || !$this->methodManager->isMethodActiveForScope($paymentMethod, $orderData)) {
            throw new \RuntimeException(__('支付方式 %{code} 不存在或未启用', ['code' => $methodCode]));
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod, $orderData);
        if (!$provider) {
            throw new \RuntimeException(__('支付提供商实例化失败'));
        }

        $transactionNo = $this->generateTransactionNo();
        $currency = strtoupper((string) ($orderData['currency'] ?? $orderData['currency_code'] ?? 'CNY'));
        $amount = (float) ($orderData['amount'] ?? 0);
        $amountMinor = (int) ($orderData['amount_minor'] ?? round($amount * 100));
        $scope = (new PaymentScopeConfigService())->resolveScope($orderData);
        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
        $runtimeCapabilities = $this->methodManager->getRuntimeCapabilities($paymentMethod, $scope);
        $actor = $this->buildActor($orderData);
        $payableSnapshot = $this->buildPayableSnapshot($orderData, $amountMinor, $currency);
        $context = array_replace($orderData, [
            'runtime_config' => $runtimeConfig,
            'runtime_capabilities' => $runtimeCapabilities,
            'payable_snapshot' => $payableSnapshot->getData(),
            'scope' => $scope['scope'],
            'environment' => $scope['environment'],
        ]);

        $methodEligibility = $this->getEligibilityService()->evaluate(
            $paymentMethod,
            $payableSnapshot,
            $actor,
            $runtimeConfig,
            $runtimeCapabilities
        );
        if (!$methodEligibility->isAvailable()) {
            throw new \RuntimeException($methodEligibility->getDisabledReasonText() ?? __('支付方式当前不可用'));
        }

        $availability = $provider->checkAvailability(AvailabilityRequest::fromArray([
            AvailabilityRequest::FIELD_PAYABLE_TYPE => $payableSnapshot->getPayableType(),
            AvailabilityRequest::FIELD_PAYABLE_ID => $payableSnapshot->getPayableId(),
            AvailabilityRequest::FIELD_METHOD_CODE => $methodCode,
            AvailabilityRequest::FIELD_SCOPE => $scope['scope'],
            AvailabilityRequest::FIELD_AMOUNT_MINOR => $payableSnapshot->getAmountMinor(),
            AvailabilityRequest::FIELD_CURRENCY_CODE => $payableSnapshot->getCurrencyCode(),
            AvailabilityRequest::FIELD_COUNTRY_CODE => (string) $payableSnapshot->getData(PayableSnapshot::FIELD_COUNTRY_CODE),
            AvailabilityRequest::FIELD_LANGUAGE_CODE => (string) $payableSnapshot->getData(PayableSnapshot::FIELD_LANGUAGE_CODE),
            AvailabilityRequest::FIELD_BUSINESS_TAGS => $payableSnapshot->getArray('business_tags'),
            AvailabilityRequest::FIELD_CONTEXT => $context,
        ]));
        if (!$availability->isAvailable()) {
            throw new \RuntimeException($availability->getDisabledReasonText() ?? __('支付方式当前不可用'));
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->setData(PaymentTransaction::schema_fields_ORDER_ID, $orderData['order_id'] ?? $orderData['payable_id'] ?? '')
            ->setData(PaymentTransaction::schema_fields_METHOD_CODE, $methodCode)
            ->setData(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
            ->setData(PaymentTransaction::schema_fields_AMOUNT, $amount)
            ->setData(PaymentTransaction::schema_fields_CURRENCY, $currency)
            ->setData(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_PENDING)
            ->setRequestData($orderData)
            ->save();

        $result = $provider->createPayment(PaymentRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => (string) ($orderData['intent_code'] ?? $transactionNo),
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => (string) ($orderData['attempt_code'] ?? $transactionNo . '-1'),
            PaymentOperationRequest::FIELD_PAYABLE_TYPE => (string) ($orderData['payable_type'] ?? 'order'),
            PaymentOperationRequest::FIELD_PAYABLE_ID => (string) ($orderData['payable_id'] ?? $orderData['order_id'] ?? ''),
            PaymentOperationRequest::FIELD_METHOD_CODE => $methodCode,
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            PaymentOperationRequest::FIELD_AMOUNT_MINOR => $amountMinor,
            PaymentOperationRequest::FIELD_CURRENCY_CODE => $currency,
            PaymentOperationRequest::FIELD_IDEMPOTENCY_KEY => (string) ($orderData['idempotency_key'] ?? $transactionNo),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $transactionNo,
            PaymentOperationRequest::FIELD_CONTEXT => $context,
        ]));

        $transaction->setResponseData($result->getData())
            ->setData(PaymentTransaction::schema_fields_STATUS, $this->mapPaymentStatus($result))
            ->save();

        if ($result->getProviderReference()) {
            $transaction->setData(PaymentTransaction::schema_fields_TRANSACTION_NO, $result->getProviderReference())
                ->save();
        }
        if ($result->isSuccessful()) {
            $transaction->setData(PaymentTransaction::schema_fields_PAID_AT, date('Y-m-d H:i:s'))
                ->save();
        }
        if ($result->getStatus() === PaymentResult::STATUS_FAILED) {
            throw new \RuntimeException((string) ($result->getData(PaymentResult::FIELD_MESSAGE) ?: __('创建支付订单失败')));
        }

        return $transaction;
    }

    /**
     * @param array<string, mixed> $callbackData
     */
    public function handleCallback(string $methodCode, array $callbackData): ?PaymentTransaction
    {
        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod) {
            throw new \RuntimeException(__('支付方式 %{code} 不存在', ['code' => $methodCode]));
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            throw new \RuntimeException(__('支付提供商实例化失败'));
        }

        $callbackRequest = CallbackRequest::fromArray([
            CallbackRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            CallbackRequest::FIELD_PAYLOAD => $callbackData,
            CallbackRequest::FIELD_SIGNATURE => (string) ($callbackData['signature'] ?? $callbackData['sign'] ?? ''),
            CallbackRequest::FIELD_RECEIVED_AT => date('Y-m-d H:i:s'),
        ]);

        $verified = $provider->verifyCallback($callbackRequest);
        if (!$verified->isVerified()) {
            throw new \RuntimeException((string) ($verified->getData(CallbackResult::FIELD_MESSAGE) ?: __('签名验证失败')));
        }
        $event = $provider->parseCallback($callbackRequest);

        $transactionNo = (string) ($event->getData(CallbackResult::FIELD_TRANSACTION_CODE)
            ?: $callbackData['transaction_no']
            ?? $callbackData['out_trade_no']
            ?? $callbackData['provider_reference']
            ?? '');
        if ($transactionNo === '') {
            throw new \RuntimeException(__('回调数据中缺少交易号'));
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
        if (!$transaction->getId()) {
            throw new \RuntimeException(__('交易记录不存在: %{no}', ['no' => $transactionNo]));
        }

        $transition = (string) ($event->getData(CallbackResult::FIELD_STATUS_TRANSITION) ?: '');
        $transaction->setCallbackData($callbackData)
            ->setData(PaymentTransaction::schema_fields_STATUS, $this->mapCallbackStatus($transition))
            ->save();
        if ($transaction->isSuccess()) {
            $transaction->setData(PaymentTransaction::schema_fields_PAID_AT, date('Y-m-d H:i:s'))
                ->save();
        }

        return $transaction;
    }

    public function queryPaymentStatus(string $transactionNo): ?PaymentTransaction
    {
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
        if (!$transaction->getId() || $transaction->isSuccess() || $transaction->isFailed()) {
            return $transaction->getId() ? $transaction : null;
        }

        $paymentMethod = $this->methodManager->getMethodByCode((string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE));
        if (!$paymentMethod) {
            return $transaction;
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            return $transaction;
        }

        $result = $provider->query(QueryRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => $transactionNo,
            PaymentOperationRequest::FIELD_METHOD_CODE => (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $transactionNo,
            PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
        ]));

        $transaction->setResponseData($result->getData())
            ->setData(PaymentTransaction::schema_fields_STATUS, $this->mapPaymentStatus($result))
            ->save();
        if ($result->isSuccessful()) {
            $transaction->setData(PaymentTransaction::schema_fields_PAID_AT, date('Y-m-d H:i:s'))
                ->save();
        }

        return $transaction;
    }

    public function refund(string $transactionNo, float $amount, string $reason = ''): RefundResult
    {
        $refund = $this->getRefundService()->refundByTransactionCode(
            $transactionNo,
            (int) round($amount * 100),
            $reason,
            [
                'source_code' => 'payment_service_refund',
                'idempotency_key' => 'payment_service_refund:' . $transactionNo . ':' . (int) round($amount * 100) . ':' . sha1($reason),
            ]
        );

        return RefundResult::fromArray([
            RefundResult::FIELD_REFUND_CODE => (string) $refund->getData(PaymentRefund::schema_fields_REFUND_CODE),
            RefundResult::FIELD_TRANSACTION_CODE => (string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE),
            RefundResult::FIELD_STATUS => $this->mapRefundStatus((string) $refund->getData(PaymentRefund::schema_fields_STATUS)),
            RefundResult::FIELD_PROVIDER_REFERENCE => (string) $refund->getData(PaymentRefund::schema_fields_PROVIDER_REFUND_ID),
            RefundResult::FIELD_MESSAGE => (string) $refund->getData(PaymentRefund::schema_fields_REASON),
            RefundResult::FIELD_PAYLOAD => [
                'requested_amount_minor' => (int) $refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR),
                'approved_amount_minor' => (int) $refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR),
                'currency_code' => (string) $refund->getData(PaymentRefund::schema_fields_CURRENCY),
                'provider_response' => $refund->getProviderResponse(),
            ],
        ]);
    }

    private function generateTransactionNo(): string
    {
        return 'PAY' . date('YmdHis') . mt_rand(100000, 999999);
    }

    private function mapPaymentStatus(PaymentResult $result): string
    {
        return match ($result->getStatus()) {
            PaymentResult::STATUS_PAID,
            PaymentResult::STATUS_CAPTURED,
            PaymentResult::STATUS_AUTHORIZED => PaymentTransaction::STATUS_SUCCESS,
            PaymentResult::STATUS_FAILED,
            PaymentResult::STATUS_UNSUPPORTED => PaymentTransaction::STATUS_FAILED,
            PaymentResult::STATUS_PROCESSING => PaymentTransaction::STATUS_PROCESSING,
            default => PaymentTransaction::STATUS_PENDING,
        };
    }

    private function mapCallbackStatus(string $transition): string
    {
        return match (strtolower($transition)) {
            'paid', 'captured', 'authorized', 'success', 'succeeded' => PaymentTransaction::STATUS_SUCCESS,
            'failed', 'cancelled', 'canceled', 'expired' => PaymentTransaction::STATUS_FAILED,
            'processing' => PaymentTransaction::STATUS_PROCESSING,
            default => PaymentTransaction::STATUS_PENDING,
        };
    }

    private function getEligibilityService(): PayablePaymentEligibilityService
    {
        return $this->eligibilityService ?? $this->objectManager->getInstance(PayablePaymentEligibilityService::class);
    }

    private function getRefundService(): PaymentRefundService
    {
        return $this->objectManager->getInstance(PaymentRefundService::class);
    }

    private function mapRefundStatus(string $status): string
    {
        return match ($status) {
            PaymentRefund::STATUS_REFUNDED => RefundResult::STATUS_REFUNDED,
            PaymentRefund::STATUS_PENDING,
            PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::STATUS_APPROVED,
            PaymentRefund::STATUS_REQUESTED => RefundResult::STATUS_PENDING,
            PaymentRefund::STATUS_UNSUPPORTED => RefundResult::STATUS_UNSUPPORTED,
            default => RefundResult::STATUS_FAILED,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildActor(array $data): ?Actor
    {
        $actor = $data['actor'] ?? null;
        if ($actor instanceof Actor) {
            return $actor;
        }
        if (\is_array($actor)) {
            return Actor::fromArray($actor);
        }

        $actorType = trim((string) ($data['actor_type'] ?? $data['payer_type'] ?? ''));
        $actorId = trim((string) ($data['actor_id'] ?? $data['payer_id'] ?? $data['customer_id'] ?? $data['user_id'] ?? ''));
        if ($actorType === '' && $actorId === '') {
            return null;
        }

        return Actor::fromArray([
            Actor::FIELD_ACTOR_TYPE => $actorType !== '' ? $actorType : 'customer',
            Actor::FIELD_ACTOR_ID => $actorId,
            Actor::FIELD_PERMISSIONS => \is_array($data['actor_permissions'] ?? null) ? $data['actor_permissions'] : [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildPayableSnapshot(array $data, int $amountMinor, string $currency): PayableSnapshot
    {
        $payableType = strtolower(trim((string) ($data['payable_type'] ?? 'order')));
        if ($payableType === '') {
            $payableType = 'order';
        }

        $payableId = trim((string) ($data['payable_id'] ?? $data['order_id'] ?? ''));
        if ($payableId === '') {
            throw new \InvalidArgumentException('payment_payable_id_required');
        }

        return PayableSnapshot::fromArray([
            PayableSnapshot::FIELD_PAYABLE_TYPE => $payableType,
            PayableSnapshot::FIELD_PAYABLE_ID => $payableId,
            PayableSnapshot::FIELD_AMOUNT_MINOR => $amountMinor,
            PayableSnapshot::FIELD_CURRENCY_CODE => strtoupper($currency),
            PayableSnapshot::FIELD_PRECISION => (int) ($data['precision'] ?? 2),
            PayableSnapshot::FIELD_COUNTRY_CODE => strtoupper((string) ($data['country_code'] ?? $data['country'] ?? '')),
            PayableSnapshot::FIELD_LANGUAGE_CODE => (string) ($data['language_code'] ?? $data['locale'] ?? 'zh_Hans_CN'),
            PayableSnapshot::FIELD_TIMEZONE => (string) ($data['timezone'] ?? date_default_timezone_get()),
            PayableSnapshot::FIELD_VERSION => (string) ($data['payable_version'] ?? $data['version'] ?? '1'),
            PayableSnapshot::FIELD_OWNER => \is_array($data['owner'] ?? null) ? $data['owner'] : [],
            PayableSnapshot::FIELD_PAYER => \is_array($data['payer'] ?? null) ? $data['payer'] : [],
            PayableSnapshot::FIELD_ITEMS => \is_array($data['items'] ?? null) ? $data['items'] : [],
            PayableSnapshot::FIELD_TOTALS => \is_array($data['totals'] ?? null) ? $data['totals'] : [],
            'business_tags' => \is_array($data['business_tags'] ?? null) ? $data['business_tags'] : [],
            'status' => (string) ($data['payable_status'] ?? $data['status'] ?? 'open'),
            'scope' => (string) ($data['scope'] ?? PaymentScopeConfigService::DEFAULT_SCOPE),
            'metadata' => \is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ]);
    }
}
