<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\RefundOperationResult;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundReserveCommand;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Api\PaymentRefundFacadeInterface;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentTransaction;

class PaymentRefundService implements PaymentRefundFacadeInterface
{
    public const ERROR_IDEMPOTENCY_CONFLICT = 'payment_refund_idempotency_conflict';
    public const ERROR_PAYMENT_NOT_CAPTURED = 'payment_refund_payment_not_captured';
    public const ERROR_AMOUNT_EXCEEDS = 'payment_refund_amount_exceeds_remaining_amount';
    public const ERROR_PROVIDER_CALL_IN_TRANSACTION = 'payment_refund_provider_call_inside_transaction';
    public const ERROR_PROVIDER_RESULT_UNKNOWN = 'payment_refund_provider_result_unknown';

    private const TERMINAL_REFUND_STATUSES = [
        PaymentRefund::STATUS_REFUNDED,
        PaymentRefund::STATUS_FAILED,
        PaymentRefund::STATUS_UNSUPPORTED,
        PaymentRefund::STATUS_CANCELLED,
    ];

    private const RESERVED_REFUND_STATUSES = [
        PaymentRefund::STATUS_REQUESTED,
        PaymentRefund::STATUS_APPROVED,
        PaymentRefund::STATUS_PROCESSING,
        PaymentRefund::STATUS_PENDING,
        PaymentRefund::STATUS_REFUNDED,
        // MOD-P2F-005：unknown / late_success_review 持续占额，仅权威 failed 释放。
        PaymentRefund::STATUS_UNKNOWN,
        PaymentRefund::STATUS_LATE_SUCCESS_REVIEW,
    ];

    /**
     * @var array<string, string[]>
     */
    private const ALLOWED_TRANSITIONS = [
        PaymentRefund::STATUS_REQUESTED => [
            PaymentRefund::STATUS_APPROVED,
            PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_CANCELLED,
        ],
        PaymentRefund::STATUS_APPROVED => [
            PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_CANCELLED,
        ],
        PaymentRefund::STATUS_PROCESSING => [
            PaymentRefund::STATUS_PENDING,
            PaymentRefund::STATUS_REFUNDED,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_UNSUPPORTED,
            PaymentRefund::STATUS_UNKNOWN,
        ],
        PaymentRefund::STATUS_PENDING => [
            PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::STATUS_REFUNDED,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_UNSUPPORTED,
            PaymentRefund::STATUS_UNKNOWN,
        ],
        PaymentRefund::STATUS_UNKNOWN => [
            PaymentRefund::STATUS_REFUNDED,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_PENDING,
            PaymentRefund::STATUS_LATE_SUCCESS_REVIEW,
        ],
        PaymentRefund::STATUS_FAILED => [
            // 权威 failed 后迟到渠道成功 → 人工对账，不自动改写为 refunded。
            PaymentRefund::STATUS_LATE_SUCCESS_REVIEW,
        ],
    ];

    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentLedgerService $ledgerService,
        private readonly ObjectManager $objectManager,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly PaymentConnectorGuard $connectors,
        private ?PaymentScopeConfigService $scopeConfigService = null
    ) {
    }

    public function reserve(RefundReserveCommand $command): RefundOperationResult
    {
        $this->validateReserveCommand($command);
        $this->connectors->assertSameDefaultConnector();
        $model = $this->newRefund();
        $callback = fn (): RefundOperationResult => $this->reserveInTransaction($command);
        if ($this->transactions->isActive($model->getConnection())) {
            return $callback();
        }

        return $this->transactions->runWrite($model->getConnection(), $callback);
    }

    public function submitToProvider(
        string $refundCode,
        string $providerRequestKey,
    ): RefundOperationResult {
        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            return RefundOperationResult::create(
                $refundCode,
                null,
                '',
                '',
                errorCode: self::ERROR_PROVIDER_CALL_IN_TRANSACTION,
            );
        }
        $refund = $this->getRefundByCode($refundCode);
        if (!$refund instanceof PaymentRefund) {
            return RefundOperationResult::create(
                $refundCode,
                null,
                '',
                '',
                errorCode: 'payment_refund_not_found',
            );
        }
        $current = $this->resultFromModel($refund);
        if (\in_array($current->getChannelStatus(), [
            PaymentRefund::CHANNEL_SUCCEEDED,
            PaymentRefund::CHANNEL_FAILED,
        ], true)) {
            return RefundOperationResult::create(
                $current->getRefundCode(),
                $current->getRefundCaseUuid(),
                $current->getStatus(),
                $current->getChannelStatus(),
                $current->getAmountMinor(),
                $current->getCurrencyCode(),
                $current->getProviderRefundId(),
                replayed: true,
                lateSuccessReview: $current->isLateSuccessReview(),
            );
        }

        $providerRequestKey = trim($providerRequestKey);
        if ($providerRequestKey === '' || \strlen($providerRequestKey) > 160) {
            return RefundOperationResult::create(
                $refundCode,
                (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
                (string)$refund->getData(PaymentRefund::schema_fields_STATUS),
                (string)$refund->getData(PaymentRefund::schema_fields_CHANNEL_STATUS),
                errorCode: 'payment_refund_provider_request_key_invalid',
            );
        }

        try {
            $paymentMethod = $this->requirePaymentMethod(
                (string)$refund->getData(PaymentRefund::schema_fields_METHOD_CODE),
            );
            $context = $refund->getMetadata();
            $scope = $this->getScopeConfigService()->resolveScope($context);
            $provider = $this->methodManager->getProviderInstance(
                $paymentMethod,
                array_replace($context, [
                    'scope' => $scope['scope'],
                    'environment' => $scope['environment'],
                ]),
            );
            if (!$provider) {
                throw new \RuntimeException('payment_provider_instance_unavailable');
            }
            $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
            $result = $provider->refund(RefundRequest::fromArray([
                RefundRequest::FIELD_REFUND_CODE => $refundCode,
                RefundRequest::FIELD_TRANSACTION_CODE => (string)$refund->getData(
                    PaymentRefund::schema_fields_TRANSACTION_CODE,
                ),
                RefundRequest::FIELD_REASON_TEXT => (string)$refund->getData(
                    PaymentRefund::schema_fields_REASON,
                ),
                PaymentOperationRequest::FIELD_INTENT_CODE => (string)$refund->getData(
                    PaymentRefund::schema_fields_INTENT_CODE,
                ),
                PaymentOperationRequest::FIELD_ATTEMPT_CODE => (string)$refund->getData(
                    PaymentRefund::schema_fields_ATTEMPT_CODE,
                ),
                PaymentOperationRequest::FIELD_PAYABLE_TYPE => (string)$refund->getData(
                    PaymentRefund::schema_fields_PAYABLE_TYPE,
                ),
                PaymentOperationRequest::FIELD_PAYABLE_ID => (string)$refund->getData(
                    PaymentRefund::schema_fields_PAYABLE_ID,
                ),
                PaymentOperationRequest::FIELD_METHOD_CODE => (string)$refund->getData(
                    PaymentRefund::schema_fields_METHOD_CODE,
                ),
                PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
                PaymentOperationRequest::FIELD_MERCHANT_ACCOUNT => (string)$refund->getData(
                    PaymentRefund::schema_fields_MERCHANT_ACCOUNT,
                ),
                PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
                PaymentOperationRequest::FIELD_AMOUNT_MINOR => (int)$refund->getData(
                    PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR,
                ),
                PaymentOperationRequest::FIELD_CURRENCY_CODE => (string)$refund->getData(
                    PaymentRefund::schema_fields_CURRENCY,
                ),
                PaymentOperationRequest::FIELD_IDEMPOTENCY_KEY => $providerRequestKey,
                PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => (string)$refund->getData(
                    PaymentRefund::schema_fields_TRANSACTION_CODE,
                ),
                PaymentOperationRequest::FIELD_CONTEXT => array_replace($context, [
                    'runtime_config' => $runtimeConfig,
                    'scope' => $scope['scope'],
                    'environment' => $scope['environment'],
                    'provider_request_key' => $providerRequestKey,
                    'operation' => 'refund',
                ]),
            ]));

            return RefundOperationResult::create(
                $refundCode,
                (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
                $this->paymentStatusForChannel($this->channelFromProviderResult($result)),
                $this->channelFromProviderResult($result),
                (int)$refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR),
                (string)$refund->getData(PaymentRefund::schema_fields_CURRENCY),
                $result->getProviderReference(),
                providerResponse: $result->getData(),
            );
        } catch (\Throwable $throwable) {
            return RefundOperationResult::create(
                $refundCode,
                (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
                PaymentRefund::STATUS_UNKNOWN,
                PaymentRefund::CHANNEL_UNKNOWN,
                (int)$refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR),
                (string)$refund->getData(PaymentRefund::schema_fields_CURRENCY),
                errorCode: self::ERROR_PROVIDER_RESULT_UNKNOWN,
                providerResponse: ['exception_class' => $throwable::class],
            );
        }
    }

    public function applyChannelResult(
        string $refundCode,
        string $channelStatus,
        ?string $providerRefundId = null,
        array $providerResponse = [],
    ): RefundOperationResult {
        $refundCode = trim($refundCode);
        $this->connectors->assertSameDefaultConnector();
        $model = $this->newRefund();
        $callback = fn (): RefundOperationResult => $this->applyChannelResultInTransaction(
            $refundCode,
            $channelStatus,
            $providerRefundId,
            $providerResponse,
        );
        if ($this->transactions->isActive($model->getConnection())) {
            return $callback();
        }

        return $this->transactions->runWrite($model->getConnection(), $callback);
    }

    public function findByRefundCaseUuid(string $refundCaseUuid): ?RefundOperationResult
    {
        $refundCaseUuid = trim($refundCaseUuid);
        if ($refundCaseUuid === '') {
            return null;
        }
        $refund = $this->newRefund()
            ->where(PaymentRefund::schema_fields_REFUND_CASE_UUID, $refundCaseUuid)
            ->find()
            ->fetch();

        return $refund instanceof PaymentRefund && $refund->getId()
            ? $this->resultFromModel($refund)
            : null;
    }

    public function getOccupiedAmountMinor(string $payableType, string $payableId): int
    {
        $amountMinor = 0;
        foreach ($this->getRefundsByPayable($payableType, $payableId, false) as $refund) {
            if (\in_array(
                (string)$refund->getData(PaymentRefund::schema_fields_STATUS),
                self::RESERVED_REFUND_STATUSES,
                true,
            )) {
                $amountMinor += (int)$refund->getData(
                    PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR,
                ) ?: (int)$refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR);
            }
        }

        return $amountMinor;
    }

    public function getCapturedAmountMinor(string $payableType, string $payableId): int
    {
        $intent = $this->loadCapturedIntent($payableType, $payableId, false);

        return $intent instanceof PaymentIntent
            ? (int)$intent->getData(PaymentIntent::schema_fields_AMOUNT_MINOR)
            : 0;
    }

    private function reserveInTransaction(RefundReserveCommand $command): RefundOperationResult
    {
        $intent = $this->loadCapturedIntent(
            $command->getPayableType(),
            $command->getPayableId(),
            true,
        );
        if (!$intent instanceof PaymentIntent) {
            return RefundOperationResult::create(
                null,
                $command->getRefundCaseUuid(),
                '',
                '',
                errorCode: self::ERROR_PAYMENT_NOT_CAPTURED,
            );
        }

        $existing = $this->loadRefundByCaseOrIdempotency($command, true);
        if ($existing instanceof PaymentRefund) {
            $same = hash_equals(
                (string)$existing->getData(PaymentRefund::schema_fields_REQUEST_HASH),
                $command->getRequestHash(),
            );
            if (!$same) {
                return RefundOperationResult::create(
                    (string)$existing->getData(PaymentRefund::schema_fields_REFUND_CODE),
                    (string)$existing->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
                    (string)$existing->getData(PaymentRefund::schema_fields_STATUS),
                    (string)$existing->getData(PaymentRefund::schema_fields_CHANNEL_STATUS),
                    errorCode: self::ERROR_IDEMPOTENCY_CONFLICT,
                );
            }

            $result = $this->resultFromModel($existing);

            return RefundOperationResult::create(
                $result->getRefundCode(),
                $result->getRefundCaseUuid(),
                $result->getStatus(),
                $result->getChannelStatus(),
                $result->getAmountMinor(),
                $result->getCurrencyCode(),
                $result->getProviderRefundId(),
                replayed: true,
                lateSuccessReview: $result->isLateSuccessReview(),
            );
        }

        $capturedAmountMinor = (int)$intent->getData(PaymentIntent::schema_fields_AMOUNT_MINOR);
        $currency = strtoupper((string)$intent->getData(PaymentIntent::schema_fields_CURRENCY_CODE));
        if ($currency !== $command->getCurrencyCode()) {
            return RefundOperationResult::create(
                null,
                $command->getRefundCaseUuid(),
                '',
                '',
                errorCode: 'payment_refund_currency_conflict',
            );
        }
        $reserved = 0;
        foreach ($this->getRefundsByIntent(
            (string)$intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
            true,
        ) as $refund) {
            if (!\in_array(
                (string)$refund->getData(PaymentRefund::schema_fields_STATUS),
                self::RESERVED_REFUND_STATUSES,
                true,
            )) {
                continue;
            }
            $reserved += (int)$refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR)
                ?: (int)$refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR);
        }
        if ($reserved + $command->getAmountMinor() > $capturedAmountMinor) {
            return RefundOperationResult::create(
                null,
                $command->getRefundCaseUuid(),
                '',
                '',
                errorCode: self::ERROR_AMOUNT_EXCEEDS,
            );
        }

        $intentCode = (string)$intent->getData(PaymentIntent::schema_fields_INTENT_CODE);
        $attempt = $this->loadSucceededAttempt($intentCode, true);
        if (!$attempt instanceof PaymentAttempt) {
            return RefundOperationResult::create(
                null,
                $command->getRefundCaseUuid(),
                '',
                '',
                errorCode: self::ERROR_PAYMENT_NOT_CAPTURED,
            );
        }
        $precision = (int)$intent->getData(PaymentIntent::schema_fields_PRECISION);
        $refundCode = 'prf_' . bin2hex(random_bytes(12));
        $attemptCode = (string)$attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE);
        $transactionCode = (string)$attempt->getData(PaymentAttempt::schema_fields_PROVIDER_REFERENCE);
        if ($transactionCode === '') {
            $transactionCode = $attemptCode;
        }
        $now = date('Y-m-d H:i:s');
        $refund = $this->newRefund();
        $refund->setData([
            PaymentRefund::schema_fields_REFUND_CODE => $refundCode,
            PaymentRefund::schema_fields_TRANSACTION_CODE => $transactionCode,
            PaymentRefund::schema_fields_LINKED_TRANSACTION_ID => null,
            PaymentRefund::schema_fields_INTENT_CODE => $intentCode,
            PaymentRefund::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentRefund::schema_fields_LINKED_ATTEMPT_ID => (int)$attempt->getId(),
            PaymentRefund::schema_fields_METHOD_CODE => (string)$attempt->getData(
                PaymentAttempt::schema_fields_METHOD_CODE,
            ),
            PaymentRefund::schema_fields_PROVIDER_CODE => (string)$attempt->getData(
                PaymentAttempt::schema_fields_PROVIDER_CODE,
            ),
            PaymentRefund::schema_fields_MERCHANT_ACCOUNT => (string)$attempt->getData(
                PaymentAttempt::schema_fields_MERCHANT_ACCOUNT,
            ),
            PaymentRefund::schema_fields_PAYABLE_TYPE => $command->getPayableType(),
            PaymentRefund::schema_fields_PAYABLE_ID => $command->getPayableId(),
            PaymentRefund::schema_fields_REASON => $command->getReason(),
            PaymentRefund::schema_fields_REQUESTED_AMOUNT => $this->minorToDecimal(
                $command->getAmountMinor(),
                $precision,
            ),
            PaymentRefund::schema_fields_APPROVED_AMOUNT => $this->minorToDecimal(
                $command->getAmountMinor(),
                $precision,
            ),
            PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR => $command->getAmountMinor(),
            PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR => $command->getAmountMinor(),
            PaymentRefund::schema_fields_CURRENCY => $currency,
            PaymentRefund::schema_fields_PRECISION => $precision,
            PaymentRefund::schema_fields_STATUS => PaymentRefund::STATUS_REQUESTED,
            PaymentRefund::schema_fields_CHANNEL_STATUS => PaymentRefund::CHANNEL_NOT_SUBMITTED,
            PaymentRefund::schema_fields_REFUND_CASE_UUID => $command->getRefundCaseUuid(),
            PaymentRefund::schema_fields_IDEMPOTENCY_KEY => $command->getIdempotencyKey(),
            PaymentRefund::schema_fields_REQUEST_HASH => $command->getRequestHash(),
            PaymentRefund::schema_fields_VERSION => 0,
            PaymentRefund::schema_fields_CREATED_AT => $now,
            PaymentRefund::schema_fields_UPDATED_AT => $now,
            PaymentRefund::schema_fields_REQUESTED_AT => $now,
        ])->setMetadata(array_replace($command->getContext(), [
            'captured_amount_minor' => $capturedAmountMinor,
            'occupied_before_minor' => $reserved,
            'scope' => (string)$attempt->getData(PaymentAttempt::schema_fields_SCOPE),
            'environment' => (string)$attempt->getData(PaymentAttempt::schema_fields_ENVIRONMENT),
        ]))->save();

        return $this->resultFromModel($refund);
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function applyChannelResultInTransaction(
        string $refundCode,
        string $channelStatus,
        ?string $providerRefundId,
        array $providerResponse,
    ): RefundOperationResult {
        $refund = $this->loadRefundForUpdate($refundCode);
        if (!$refund instanceof PaymentRefund) {
            return RefundOperationResult::create(
                $refundCode,
                null,
                '',
                '',
                errorCode: 'payment_refund_not_found',
            );
        }
        $incoming = $this->normalizeChannelStatus($channelStatus);
        if ($incoming === '') {
            return RefundOperationResult::create(
                $refundCode,
                (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
                (string)$refund->getData(PaymentRefund::schema_fields_STATUS),
                (string)$refund->getData(PaymentRefund::schema_fields_CHANNEL_STATUS),
                errorCode: 'payment_refund_channel_status_invalid',
            );
        }
        $previousChannel = (string)$refund->getData(PaymentRefund::schema_fields_CHANNEL_STATUS);
        $previousStatus = (string)$refund->getData(PaymentRefund::schema_fields_STATUS);
        if ($previousStatus === PaymentRefund::STATUS_LATE_SUCCESS_REVIEW
            || $previousChannel === PaymentRefund::CHANNEL_SUCCEEDED
        ) {
            $result = $this->resultFromModel($refund);

            return RefundOperationResult::create(
                $result->getRefundCode(),
                $result->getRefundCaseUuid(),
                $result->getStatus(),
                $result->getChannelStatus(),
                $result->getAmountMinor(),
                $result->getCurrencyCode(),
                $result->getProviderRefundId(),
                replayed: true,
                lateSuccessReview: $result->isLateSuccessReview(),
            );
        }
        $lateSuccess = $previousChannel === PaymentRefund::CHANNEL_FAILED
            && $incoming === PaymentRefund::CHANNEL_SUCCEEDED;
        if ($previousChannel === PaymentRefund::CHANNEL_FAILED && !$lateSuccess) {
            $result = $this->resultFromModel($refund);

            return RefundOperationResult::create(
                $result->getRefundCode(),
                $result->getRefundCaseUuid(),
                $result->getStatus(),
                $result->getChannelStatus(),
                $result->getAmountMinor(),
                $result->getCurrencyCode(),
                $result->getProviderRefundId(),
                replayed: true,
            );
        }

        $now = date('Y-m-d H:i:s');
        $status = $lateSuccess
            ? PaymentRefund::STATUS_LATE_SUCCESS_REVIEW
            : $this->paymentStatusForChannel($incoming);
        $refund->setData(PaymentRefund::schema_fields_CHANNEL_STATUS, $incoming)
            ->setData(PaymentRefund::schema_fields_STATUS, $status)
            ->setData(PaymentRefund::schema_fields_PROVIDER_REFUND_ID, $providerRefundId)
            ->setData(
                PaymentRefund::schema_fields_VERSION,
                (int)$refund->getData(PaymentRefund::schema_fields_VERSION) + 1,
            )
            ->setData(PaymentRefund::schema_fields_UPDATED_AT, $now)
            ->setProviderResponse($providerResponse);
        if ($incoming === PaymentRefund::CHANNEL_SUCCEEDED) {
            $refund->setData(PaymentRefund::schema_fields_COMPLETED_AT, $now);
        } elseif ($incoming === PaymentRefund::CHANNEL_FAILED) {
            $refund->setData(PaymentRefund::schema_fields_FAILED_AT, $now);
        }
        $refund->save();

        if ($incoming === PaymentRefund::CHANNEL_SUCCEEDED) {
            $this->recordPersistentRefundLedger($refund, $lateSuccess);
        }

        return $this->resultFromModel($refund);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function refundByTransactionCode(string $transactionCode, int $requestedAmountMinor, string $reason = '', array $context = []): PaymentRefund
    {
        $refund = $this->requestRefund($transactionCode, $requestedAmountMinor, $reason, $context);

        return $this->submitRefund($refund, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function refundAmountByTransactionCode(string $transactionCode, string $requestedAmount, string $reason = '', array $context = []): PaymentRefund
    {
        $transaction = $this->requireRefundableTransaction($transactionCode);
        $currency = $this->resolveTransactionCurrency($transaction, $context);
        $precision = $this->resolveMoneyPrecision($currency, $transaction->getRequestData(), $context);

        return $this->refundByTransactionCode($transactionCode, $this->decimalToMinor($requestedAmount, $precision), $reason, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function requestRefund(string $transactionCode, int $requestedAmountMinor, string $reason = '', array $context = []): PaymentRefund
    {
        $transactionModel = $this->newTransaction();
        if (!$this->transactions->isActive($transactionModel->getConnection())) {
            return $this->transactions->runWrite(
                $transactionModel->getConnection(),
                fn (): PaymentRefund => $this->requestRefund(
                    $transactionCode,
                    $requestedAmountMinor,
                    $reason,
                    $context,
                ),
            );
        }
        $this->assertPositiveAmount($requestedAmountMinor, 'payment_refund_requested_amount_required');
        $transaction = $this->requireRefundableTransaction($transactionCode);
        $transactionCode = (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO);
        $currency = $this->resolveTransactionCurrency($transaction, $context);
        $precision = $this->resolveMoneyPrecision($currency, $transaction->getRequestData(), $context);
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        $requestHash = hash('sha256', json_encode([
            'transaction_code' => $transactionCode,
            'amount_minor' => $requestedAmountMinor,
            'currency' => $currency,
            'reason' => trim($reason),
        ], JSON_UNESCAPED_SLASHES) ?: '');
        if ($idempotencyKey !== '') {
            $existing = $this->getRefundByIdempotencyKey($transactionCode, $idempotencyKey);
            if ($existing instanceof PaymentRefund) {
                $storedHash = (string)$existing->getData(PaymentRefund::schema_fields_REQUEST_HASH);
                if ($storedHash !== '' && !hash_equals($storedHash, $requestHash)) {
                    throw new \LogicException(self::ERROR_IDEMPOTENCY_CONFLICT);
                }
                return $existing;
            }
        }
        $transactionAmountMinor = $this->getTransactionAmountMinor($transaction, $precision);
        $reservedAmountMinor = $this->getReservedRefundAmountMinor($transactionCode);
        $remainingAmountMinor = $transactionAmountMinor - $reservedAmountMinor;

        if ($requestedAmountMinor > $remainingAmountMinor) {
            throw new \LogicException('payment_refund_amount_exceeds_remaining_amount');
        }

        $paymentContext = $this->buildPaymentContext($transaction, $context);
        $attemptCode = (string) ($paymentContext['attempt_code'] ?? '');
        $linkedAttemptId = $attemptCode !== '' ? $this->resolveAttemptId($attemptCode) : null;
        $now = date('Y-m-d H:i:s');
        $refund = $this->newRefund();
        $refund->setData(PaymentRefund::schema_fields_REFUND_CODE, (string) ($context['refund_code'] ?? $this->generateRefundCode()))
            ->setData(PaymentRefund::schema_fields_TRANSACTION_CODE, $transactionCode)
            ->setData(PaymentRefund::schema_fields_LINKED_TRANSACTION_ID, (int) $transaction->getData(PaymentTransaction::schema_fields_ID))
            ->setData(PaymentRefund::schema_fields_INTENT_CODE, (string) ($paymentContext['intent_code'] ?? $transactionCode))
            ->setData(PaymentRefund::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->setData(PaymentRefund::schema_fields_LINKED_ATTEMPT_ID, $linkedAttemptId)
            ->setData(PaymentRefund::schema_fields_METHOD_CODE, (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE))
            ->setData(PaymentRefund::schema_fields_MERCHANT_ACCOUNT, (string) ($paymentContext['merchant_account'] ?? ''))
            ->setData(PaymentRefund::schema_fields_PAYABLE_TYPE, (string) ($paymentContext['payable_type'] ?? 'order'))
            ->setData(PaymentRefund::schema_fields_PAYABLE_ID, (string) ($paymentContext['payable_id'] ?? $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID)))
            ->setData(PaymentRefund::schema_fields_REASON, trim($reason))
            ->setData(PaymentRefund::schema_fields_REQUESTED_AMOUNT, $this->minorToDecimal($requestedAmountMinor, $precision))
            ->setData(PaymentRefund::schema_fields_APPROVED_AMOUNT, $this->minorToDecimal(0, $precision))
            ->setData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR, $requestedAmountMinor)
            ->setData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR, 0)
            ->setData(PaymentRefund::schema_fields_CURRENCY, $currency)
            ->setData(PaymentRefund::schema_fields_PRECISION, $precision)
            ->setData(PaymentRefund::schema_fields_STATUS, PaymentRefund::STATUS_REQUESTED)
            ->setData(PaymentRefund::schema_fields_CHANNEL_STATUS, PaymentRefund::CHANNEL_NOT_SUBMITTED)
            ->setData(PaymentRefund::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->setData(PaymentRefund::schema_fields_REQUEST_HASH, $requestHash)
            ->setData(PaymentRefund::schema_fields_VERSION, 0)
            ->setData(PaymentRefund::schema_fields_CREATED_AT, $now)
            ->setData(PaymentRefund::schema_fields_UPDATED_AT, $now)
            ->setData(PaymentRefund::schema_fields_REQUESTED_AT, $now)
            ->setMetadata([
                'requested_by' => (string) ($context['requested_by'] ?? ''),
                'source_code' => (string) ($context['source_code'] ?? ''),
                'transaction_amount_minor' => $transactionAmountMinor,
                'previous_reserved_refund_amount_minor' => $reservedAmountMinor,
            ])
            ->save();

        return $refund;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function submitRefund(PaymentRefund|string $refund, array $context = []): PaymentRefund
    {
        $refund = \is_string($refund) ? $this->requireRefundByCode($refund) : $refund;
        if ($refund->isTerminal()) {
            return $refund;
        }

        $transaction = $this->requireTransactionByCode((string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE));
        $paymentMethod = $this->requirePaymentMethod((string) $refund->getData(PaymentRefund::schema_fields_METHOD_CODE));
        $paymentContext = $this->buildPaymentContext($transaction, array_replace($refund->getMetadata(), $context));
        $provider = $this->methodManager->getProviderInstance($paymentMethod, $paymentContext);
        if (!$provider) {
            throw new \RuntimeException('payment_provider_instance_unavailable');
        }

        $scope = $this->getScopeConfigService()->resolveScope($paymentContext);
        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
        $paymentContext['runtime_config'] = $runtimeConfig;
        $paymentContext['scope'] = $scope['scope'];
        $paymentContext['environment'] = $scope['environment'];

        $approvedAmountMinor = (int) $refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR);
        $precision = (int) $refund->getData(PaymentRefund::schema_fields_PRECISION);
        $now = date('Y-m-d H:i:s');

        $this->prepareRefundForProvider($refund, $provider->getProviderCode(), $approvedAmountMinor, $precision, $now);

        $result = $provider->refund(RefundRequest::fromArray([
            RefundRequest::FIELD_REFUND_CODE => (string) $refund->getData(PaymentRefund::schema_fields_REFUND_CODE),
            RefundRequest::FIELD_TRANSACTION_CODE => (string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE),
            RefundRequest::FIELD_REASON_TEXT => (string) $refund->getData(PaymentRefund::schema_fields_REASON),
            PaymentOperationRequest::FIELD_INTENT_CODE => (string) $refund->getData(PaymentRefund::schema_fields_INTENT_CODE),
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => (string) $refund->getData(PaymentRefund::schema_fields_ATTEMPT_CODE),
            PaymentOperationRequest::FIELD_PAYABLE_TYPE => (string) $refund->getData(PaymentRefund::schema_fields_PAYABLE_TYPE),
            PaymentOperationRequest::FIELD_PAYABLE_ID => (string) $refund->getData(PaymentRefund::schema_fields_PAYABLE_ID),
            PaymentOperationRequest::FIELD_METHOD_CODE => (string) $refund->getData(PaymentRefund::schema_fields_METHOD_CODE),
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_MERCHANT_ACCOUNT => (string) $refund->getData(PaymentRefund::schema_fields_MERCHANT_ACCOUNT),
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            PaymentOperationRequest::FIELD_AMOUNT_MINOR => $approvedAmountMinor,
            PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $refund->getData(PaymentRefund::schema_fields_CURRENCY),
            PaymentOperationRequest::FIELD_IDEMPOTENCY_KEY => (string) $refund->getData(PaymentRefund::schema_fields_IDEMPOTENCY_KEY),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => (string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE),
            PaymentOperationRequest::FIELD_CONTEXT => $paymentContext,
        ]));

        $this->applyProviderResult($refund, $transaction, $result);

        return $refund;
    }

    public function getRefundByCode(string $refundCode): ?PaymentRefund
    {
        $refundCode = trim($refundCode);
        if ($refundCode === '') {
            return null;
        }

        $refund = $this->newRefund();
        $refund->load(PaymentRefund::schema_fields_REFUND_CODE, $refundCode);

        return $refund->getId() ? $refund : null;
    }

    /**
     * @return PaymentRefund[]
     */
    public function getRefundsByTransactionCode(string $transactionCode): array
    {
        $transactionCode = trim($transactionCode);
        if ($transactionCode === '') {
            return [];
        }

        $collection = $this->newRefund()
            ->where(PaymentRefund::schema_fields_TRANSACTION_CODE, $transactionCode)
            ->order(PaymentRefund::schema_fields_CREATED_AT, 'ASC')
            ->select()
            ->fetch();

        return $this->collectionItems($collection, PaymentRefund::class);
    }

    public function getRefundedAmountMinor(string $transactionCode): int
    {
        $amountMinor = 0;
        foreach ($this->getRefundsByTransactionCode($transactionCode) as $refund) {
            if ((string) $refund->getData(PaymentRefund::schema_fields_STATUS) === PaymentRefund::STATUS_REFUNDED) {
                $amountMinor += (int) $refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR);
            }
        }

        return $amountMinor;
    }

    public function getRefundableAmountMinor(string $transactionCode): int
    {
        $transaction = $this->requireRefundableTransaction($transactionCode);
        $currency = $this->resolveTransactionCurrency($transaction);
        $precision = $this->resolveMoneyPrecision($currency, $transaction->getRequestData());

        return $this->getTransactionAmountMinor($transaction, $precision) - $this->getReservedRefundAmountMinor($transactionCode);
    }

    /**
     * @return array{code: string, transaction: ?PaymentTransaction, refund: ?PaymentRefund, refunds: PaymentRefund[], ledger_entries: PaymentLedger[]}
     */
    public function findPaymentDetailByCode(string $code): array
    {
        $code = trim($code);
        $transaction = $code !== '' ? $this->getTransactionByCode($code) : null;
        $refund = $code !== '' ? $this->getRefundByCode($code) : null;
        $ledgerEntries = $this->ledgerService->getEntriesByCode($code);

        if (!$transaction && $refund instanceof PaymentRefund) {
            $transaction = $this->getTransactionByCode((string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE));
        }
        if (!$transaction && $ledgerEntries !== []) {
            $transactionCode = (string) $ledgerEntries[0]->getData(PaymentLedger::schema_fields_TRANSACTION_CODE);
            if ($transactionCode !== '') {
                $transaction = $this->getTransactionByCode($transactionCode);
            }
        }

        $transactionCode = $transaction instanceof PaymentTransaction
            ? (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO)
            : '';

        return [
            'code' => $code,
            'transaction' => $transaction,
            'refund' => $refund,
            'refunds' => $transactionCode !== '' ? $this->getRefundsByTransactionCode($transactionCode) : [],
            'ledger_entries' => $ledgerEntries,
        ];
    }

    private function applyProviderResult(PaymentRefund $refund, PaymentTransaction $transaction, RefundResult $result): void
    {
        $targetStatus = $this->mapRefundResultStatus($result);
        $now = date('Y-m-d H:i:s');
        $this->moveToStatus($refund, $targetStatus);
        if ($result->getProviderReference()) {
            $refund->setData(PaymentRefund::schema_fields_PROVIDER_REFUND_ID, $result->getProviderReference());
        }
        $refund->setProviderResponse($result->getData())
            ->setData(PaymentRefund::schema_fields_UPDATED_AT, $now);

        if ($targetStatus === PaymentRefund::STATUS_REFUNDED) {
            $refund->setData(PaymentRefund::schema_fields_COMPLETED_AT, $now);
        } elseif (\in_array($targetStatus, [PaymentRefund::STATUS_FAILED, PaymentRefund::STATUS_UNSUPPORTED], true)) {
            $refund->setData(PaymentRefund::schema_fields_FAILED_AT, $now);
        }
        $refund->save();

        if ($targetStatus === PaymentRefund::STATUS_REFUNDED) {
            $this->ledgerService->recordRefund($refund, $transaction);
            $this->syncTransactionRefundStatus($transaction);
        }
    }

    private function prepareRefundForProvider(PaymentRefund $refund, string $providerCode, int $approvedAmountMinor, int $precision, string $now): void
    {
        $currentStatus = (string) $refund->getData(PaymentRefund::schema_fields_STATUS);
        if ($currentStatus === PaymentRefund::STATUS_PROCESSING) {
            throw new \LogicException('payment_refund_already_processing');
        }

        if ($currentStatus === PaymentRefund::STATUS_REQUESTED) {
            $this->moveToStatus($refund, PaymentRefund::STATUS_APPROVED);
            $refund->setData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR, $approvedAmountMinor)
                ->setData(PaymentRefund::schema_fields_APPROVED_AMOUNT, $this->minorToDecimal($approvedAmountMinor, $precision))
                ->setData(PaymentRefund::schema_fields_PROVIDER_CODE, $providerCode)
                ->setData(PaymentRefund::schema_fields_APPROVED_AT, $now)
                ->setData(PaymentRefund::schema_fields_UPDATED_AT, $now)
                ->save();
        } elseif ($currentStatus === PaymentRefund::STATUS_APPROVED) {
            $refund->setData(PaymentRefund::schema_fields_PROVIDER_CODE, $providerCode)
                ->setData(PaymentRefund::schema_fields_UPDATED_AT, $now)
                ->save();
        }

        $this->moveToStatus($refund, PaymentRefund::STATUS_PROCESSING);
        $refund->setData(PaymentRefund::schema_fields_UPDATED_AT, $now)->save();
    }

    private function syncTransactionRefundStatus(PaymentTransaction $transaction): void
    {
        $transactionCode = (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO);
        $currency = $this->resolveTransactionCurrency($transaction);
        $precision = $this->resolveMoneyPrecision($currency, $transaction->getRequestData());
        $transactionAmountMinor = $this->getTransactionAmountMinor($transaction, $precision);
        $refundedAmountMinor = $this->getRefundedAmountMinor($transactionCode);

        if ($refundedAmountMinor >= $transactionAmountMinor) {
            $transaction->setData(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_REFUNDED)
                ->save();
        }
    }

    private function requireRefundableTransaction(string $transactionCode): PaymentTransaction
    {
        $transaction = $this->requireTransactionByCode($transactionCode);
        if (!\in_array((string) $transaction->getData(PaymentTransaction::schema_fields_STATUS), [
            PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::STATUS_REFUNDED,
        ], true)) {
            throw new \LogicException('payment_refund_transaction_status_invalid');
        }

        return $transaction;
    }

    private function requireTransactionByCode(string $transactionCode): PaymentTransaction
    {
        $transaction = $this->getTransactionByCode($transactionCode);
        if (!$transaction instanceof PaymentTransaction) {
            throw new \RuntimeException('payment_transaction_not_found');
        }

        return $transaction;
    }

    private function getTransactionByCode(string $transactionCode): ?PaymentTransaction
    {
        $transactionCode = trim($transactionCode);
        if ($transactionCode === '') {
            return null;
        }

        $transaction = $this->newTransaction();
        if ($this->transactions->isActive($transaction->getConnection())) {
            $transaction->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionCode);
            if (!$this->isSqlite($transaction)) {
                $transaction->additional('FOR UPDATE');
            }
            $transaction = $transaction->find()->fetch();
        } else {
            $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionCode);
        }

        return $transaction instanceof PaymentTransaction && $transaction->getId()
            ? $transaction
            : null;
    }

    private function requireRefundByCode(string $refundCode): PaymentRefund
    {
        $refund = $this->getRefundByCode($refundCode);
        if (!$refund instanceof PaymentRefund) {
            throw new \RuntimeException('payment_refund_not_found');
        }

        return $refund;
    }

    private function requirePaymentMethod(string $methodCode): PaymentMethod
    {
        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod instanceof PaymentMethod) {
            throw new \RuntimeException('payment_method_not_found');
        }

        return $paymentMethod;
    }

    private function getRefundByIdempotencyKey(string $transactionCode, string $idempotencyKey): ?PaymentRefund
    {
        $collection = $this->newRefund()
            ->where(PaymentRefund::schema_fields_TRANSACTION_CODE, $transactionCode)
            ->where(PaymentRefund::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->select()
            ->fetch();
        $items = $this->collectionItems($collection, PaymentRefund::class);

        return $items[0] ?? null;
    }

    private function getReservedRefundAmountMinor(string $transactionCode): int
    {
        $amountMinor = 0;
        foreach ($this->getRefundsByTransactionCode($transactionCode) as $refund) {
            if (\in_array((string) $refund->getData(PaymentRefund::schema_fields_STATUS), self::RESERVED_REFUND_STATUSES, true)) {
                $amountMinor += (int) $refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR)
                    ?: (int) $refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR);
            }
        }

        return $amountMinor;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildPaymentContext(PaymentTransaction $transaction, array $context = []): array
    {
        return array_replace_recursive(
            $transaction->getRequestData(),
            $transaction->getResponseData(),
            [
                'transaction_code' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
                'method_code' => (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
                'currency_code' => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
                'payable_id' => (string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID),
            ],
            $context
        );
    }

    private function resolveAttemptId(string $attemptCode): ?int
    {
        $attempt = $this->objectManager->getInstance(PaymentAttempt::class);
        $attempt->load(PaymentAttempt::schema_fields_ATTEMPT_CODE, $attemptCode);

        return $attempt->getId() ? (int) $attempt->getData(PaymentAttempt::schema_fields_ID) : null;
    }

    private function moveToStatus(PaymentRefund $refund, string $targetStatus): void
    {
        $currentStatus = (string) $refund->getData(PaymentRefund::schema_fields_STATUS);
        if ($currentStatus === $targetStatus) {
            return;
        }
        if (\in_array($currentStatus, self::TERMINAL_REFUND_STATUSES, true)) {
            throw new \LogicException('payment_refund_terminal_status_transition_not_allowed:' . $currentStatus . ':' . $targetStatus);
        }
        if (!\in_array($targetStatus, self::ALLOWED_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw new \LogicException('payment_refund_status_transition_invalid:' . $currentStatus . ':' . $targetStatus);
        }

        $refund->setData(PaymentRefund::schema_fields_STATUS, $targetStatus);
    }

    private function mapRefundResultStatus(RefundResult $result): string
    {
        return match ($result->getStatus()) {
            RefundResult::STATUS_REFUNDED => PaymentRefund::STATUS_REFUNDED,
            RefundResult::STATUS_PROCESSING => PaymentRefund::STATUS_PROCESSING,
            RefundResult::STATUS_FAILED => PaymentRefund::STATUS_FAILED,
            RefundResult::STATUS_UNSUPPORTED => PaymentRefund::STATUS_UNSUPPORTED,
            default => PaymentRefund::STATUS_PENDING,
        };
    }

    private function resolveTransactionCurrency(PaymentTransaction $transaction, array $context = []): string
    {
        $currency = strtoupper(trim((string) ($context['currency'] ?? $context['currency_code'] ?? $transaction->getData(PaymentTransaction::schema_fields_CURRENCY))));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('payment_refund_currency_invalid');
        }

        return $currency;
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $context
     */
    private function resolveMoneyPrecision(string $currency, array $requestData = [], array $context = []): int
    {
        $precision = $context['precision'] ?? $requestData['precision'] ?? null;
        if ($precision !== null && $precision !== '') {
            $precision = (int) $precision;
            if ($precision < 0 || $precision > 8) {
                throw new \InvalidArgumentException('payment_money_precision_invalid');
            }

            return $precision;
        }

        return \in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)
            ? 0
            : 2;
    }

    private function getTransactionAmountMinor(PaymentTransaction $transaction, int $precision): int
    {
        $requestData = $transaction->getRequestData();
        if (isset($requestData['amount_minor'])) {
            return $this->normalizeAmountMinor($requestData['amount_minor']);
        }

        return $this->decimalToMinor((string) $transaction->getData(PaymentTransaction::schema_fields_AMOUNT), $precision);
    }

    private function decimalToMinor(string $amount, int $precision): int
    {
        $amount = trim($amount);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException('payment_amount_decimal_invalid');
        }
        if (str_starts_with($amount, '-')) {
            throw new \InvalidArgumentException('payment_amount_decimal_must_be_positive');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (\strlen($fraction) > $precision) {
            $discarded = substr($fraction, $precision);
            if (trim($discarded, '0') !== '') {
                throw new \InvalidArgumentException('payment_amount_precision_exceeded');
            }
            $fraction = substr($fraction, 0, $precision);
        }
        $fraction = str_pad($fraction, $precision, '0');

        return ((int) $whole * (10 ** $precision)) + (int) $fraction;
    }

    private function minorToDecimal(int $amountMinor, int $precision): string
    {
        return $this->ledgerService->minorToDecimal($amountMinor, $precision);
    }

    private function normalizeAmountMinor(mixed $amountMinor): int
    {
        if (\is_float($amountMinor)) {
            throw new \InvalidArgumentException('payment_amount_minor_must_be_integer');
        }
        if (\is_int($amountMinor)) {
            return $amountMinor;
        }
        if (\is_string($amountMinor) && preg_match('/^-?\d+$/', $amountMinor)) {
            return (int) $amountMinor;
        }

        throw new \InvalidArgumentException('payment_amount_minor_must_be_integer');
    }

    private function assertPositiveAmount(int $amountMinor, string $errorCode): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException($errorCode);
        }
    }

    private function generateRefundCode(): string
    {
        return 'refund_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }

    private function newRefund(): PaymentRefund
    {
        return $this->objectManager->getInstance(PaymentRefund::class, [], false);
    }

    private function newTransaction(): PaymentTransaction
    {
        return $this->objectManager->getInstance(PaymentTransaction::class);
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        if ($this->scopeConfigService === null) {
            $this->scopeConfigService = new PaymentScopeConfigService($this->objectManager);
        }

        return $this->scopeConfigService;
    }

    private function validateReserveCommand(RefundReserveCommand $command): void
    {
        if (\preg_match('/^[a-f0-9-]{36}$/', strtolower($command->getRefundCaseUuid())) !== 1) {
            throw new \InvalidArgumentException('payment_refund_case_uuid_invalid');
        }
        if (\preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $command->getPayableType()) !== 1
            || $command->getPayableId() === ''
            || \strlen($command->getPayableId()) > 128
        ) {
            throw new \InvalidArgumentException('payment_refund_payable_invalid');
        }
        if ($command->getIdempotencyKey() === ''
            || \strlen($command->getIdempotencyKey()) > 128
        ) {
            throw new \InvalidArgumentException('payment_refund_idempotency_key_invalid');
        }
        if (\preg_match('/^[a-f0-9]{64}$/', $command->getRequestHash()) !== 1) {
            throw new \InvalidArgumentException('payment_refund_request_hash_invalid');
        }
        $this->assertPositiveAmount(
            $command->getAmountMinor(),
            'payment_refund_requested_amount_required',
        );
        if (\preg_match('/^[A-Z]{3}$/', $command->getCurrencyCode()) !== 1) {
            throw new \InvalidArgumentException('payment_refund_currency_invalid');
        }
    }

    private function loadCapturedIntent(
        string $payableType,
        string $payableId,
        bool $forUpdate,
    ): ?PaymentIntent {
        $model = $this->objectManager->getInstance(PaymentIntent::class, [], false);
        $model->where(PaymentIntent::schema_fields_PAYABLE_TYPE, strtolower(trim($payableType)))
            ->where(PaymentIntent::schema_fields_PAYABLE_ID, trim($payableId))
            ->where(PaymentIntent::schema_fields_STATUS, [
                PaymentIntent::STATUS_AUTHORIZED,
                PaymentIntent::STATUS_CAPTURED,
                PaymentIntent::STATUS_PAID,
                PaymentIntent::STATUS_REFUNDING,
                PaymentIntent::STATUS_PARTIALLY_REFUNDED,
                PaymentIntent::STATUS_REFUNDED,
                'succeeded',
            ], 'IN')
            ->order(PaymentIntent::schema_fields_CREATED_AT, 'DESC')
            ->limit(1);
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $intent = $model->find()->fetch();

        return $intent instanceof PaymentIntent && $intent->getId() ? $intent : null;
    }

    private function loadSucceededAttempt(string $intentCode, bool $forUpdate): ?PaymentAttempt
    {
        $model = $this->objectManager->getInstance(PaymentAttempt::class, [], false);
        $model->where(PaymentAttempt::schema_fields_INTENT_CODE, $intentCode)
            ->where(PaymentAttempt::schema_fields_STATUS, PaymentAttempt::STATUS_SUCCEEDED)
            ->order(PaymentAttempt::schema_fields_ID, 'DESC')
            ->limit(1);
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $attempt = $model->find()->fetch();

        return $attempt instanceof PaymentAttempt && $attempt->getId() ? $attempt : null;
    }

    private function loadRefundByCaseOrIdempotency(
        RefundReserveCommand $command,
        bool $forUpdate,
    ): ?PaymentRefund {
        $byCase = $this->newRefund()
            ->where(
                PaymentRefund::schema_fields_REFUND_CASE_UUID,
                $command->getRefundCaseUuid(),
            )
            ->limit(1);
        if ($forUpdate && !$this->isSqlite($byCase)) {
            $byCase->additional('FOR UPDATE');
        }
        $refund = $byCase->find()->fetch();
        if ($refund instanceof PaymentRefund && $refund->getId()) {
            return $refund;
        }

        $byIdempotency = $this->newRefund()
            ->where(PaymentRefund::schema_fields_PAYABLE_TYPE, $command->getPayableType())
            ->where(PaymentRefund::schema_fields_PAYABLE_ID, $command->getPayableId())
            ->where(
                PaymentRefund::schema_fields_IDEMPOTENCY_KEY,
                $command->getIdempotencyKey(),
            )
            ->limit(1);
        if ($forUpdate && !$this->isSqlite($byIdempotency)) {
            $byIdempotency->additional('FOR UPDATE');
        }
        $refund = $byIdempotency->find()->fetch();

        return $refund instanceof PaymentRefund && $refund->getId() ? $refund : null;
    }

    /**
     * @return PaymentRefund[]
     */
    private function getRefundsByIntent(string $intentCode, bool $forUpdate): array
    {
        $model = $this->newRefund()
            ->where(PaymentRefund::schema_fields_INTENT_CODE, trim($intentCode))
            ->order(PaymentRefund::schema_fields_ID, 'ASC');
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }

        return $this->collectionItems($model->select()->fetch(), PaymentRefund::class);
    }

    /**
     * @return PaymentRefund[]
     */
    private function getRefundsByPayable(
        string $payableType,
        string $payableId,
        bool $forUpdate,
    ): array {
        $model = $this->newRefund()
            ->where(PaymentRefund::schema_fields_PAYABLE_TYPE, strtolower(trim($payableType)))
            ->where(PaymentRefund::schema_fields_PAYABLE_ID, trim($payableId))
            ->order(PaymentRefund::schema_fields_ID, 'ASC');
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }

        return $this->collectionItems($model->select()->fetch(), PaymentRefund::class);
    }

    private function loadRefundForUpdate(string $refundCode): ?PaymentRefund
    {
        $model = $this->newRefund()
            ->where(PaymentRefund::schema_fields_REFUND_CODE, trim($refundCode));
        if (!$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $refund = $model->find()->fetch();

        return $refund instanceof PaymentRefund && $refund->getId() ? $refund : null;
    }

    private function resultFromModel(PaymentRefund $refund): RefundOperationResult
    {
        return RefundOperationResult::create(
            (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CODE),
            (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CASE_UUID),
            (string)$refund->getData(PaymentRefund::schema_fields_STATUS),
            (string)($refund->getData(PaymentRefund::schema_fields_CHANNEL_STATUS)
                ?: PaymentRefund::CHANNEL_NOT_SUBMITTED),
            (int)$refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR)
                ?: (int)$refund->getData(PaymentRefund::schema_fields_REQUESTED_AMOUNT_MINOR),
            (string)$refund->getData(PaymentRefund::schema_fields_CURRENCY),
            ($value = trim((string)$refund->getData(
                PaymentRefund::schema_fields_PROVIDER_REFUND_ID,
            ))) !== '' ? $value : null,
            lateSuccessReview: (string)$refund->getData(
                PaymentRefund::schema_fields_STATUS,
            ) === PaymentRefund::STATUS_LATE_SUCCESS_REVIEW,
        );
    }

    private function normalizeChannelStatus(string $channelStatus): string
    {
        return match (strtolower(trim($channelStatus))) {
            PaymentRefund::CHANNEL_NOT_SUBMITTED => PaymentRefund::CHANNEL_NOT_SUBMITTED,
            PaymentRefund::CHANNEL_SUBMITTED => PaymentRefund::CHANNEL_SUBMITTED,
            'accepted', PaymentRefund::CHANNEL_PENDING, 'processing' => PaymentRefund::CHANNEL_PENDING,
            'timeout', PaymentRefund::CHANNEL_UNKNOWN => PaymentRefund::CHANNEL_UNKNOWN,
            'success', 'refunded', PaymentRefund::CHANNEL_SUCCEEDED => PaymentRefund::CHANNEL_SUCCEEDED,
            'unsupported', PaymentRefund::CHANNEL_FAILED => PaymentRefund::CHANNEL_FAILED,
            default => '',
        };
    }

    private function channelFromProviderResult(RefundResult $result): string
    {
        return match ($result->getStatus()) {
            RefundResult::STATUS_REFUNDED => PaymentRefund::CHANNEL_SUCCEEDED,
            RefundResult::STATUS_FAILED,
            RefundResult::STATUS_UNSUPPORTED => PaymentRefund::CHANNEL_FAILED,
            RefundResult::STATUS_PROCESSING,
            RefundResult::STATUS_PENDING => PaymentRefund::CHANNEL_PENDING,
            default => PaymentRefund::CHANNEL_UNKNOWN,
        };
    }

    private function paymentStatusForChannel(string $channelStatus): string
    {
        return match ($channelStatus) {
            PaymentRefund::CHANNEL_NOT_SUBMITTED => PaymentRefund::STATUS_REQUESTED,
            PaymentRefund::CHANNEL_SUBMITTED => PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::CHANNEL_PENDING => PaymentRefund::STATUS_PENDING,
            PaymentRefund::CHANNEL_UNKNOWN => PaymentRefund::STATUS_UNKNOWN,
            PaymentRefund::CHANNEL_SUCCEEDED => PaymentRefund::STATUS_REFUNDED,
            PaymentRefund::CHANNEL_FAILED => PaymentRefund::STATUS_FAILED,
            default => PaymentRefund::STATUS_UNKNOWN,
        };
    }

    private function recordPersistentRefundLedger(
        PaymentRefund $refund,
        bool $lateSuccess,
    ): void {
        $refundCode = (string)$refund->getData(PaymentRefund::schema_fields_REFUND_CODE);
        $ledgerCode = ($lateSuccess ? 'pl_refund_late_' : 'pl_refund_')
            . substr(hash('sha256', $refundCode), 0, 48);
        $existing = $this->objectManager->getInstance(PaymentLedger::class, [], false)
            ->where(PaymentLedger::schema_fields_LEDGER_CODE, $ledgerCode)
            ->find()
            ->fetch();
        if ($existing instanceof PaymentLedger && $existing->getId()) {
            return;
        }
        $this->ledgerService->createEntry([
            'ledger_code' => $ledgerCode,
            'ledger_type' => $lateSuccess
                ? PaymentLedger::TYPE_ADJUSTMENT
                : PaymentLedger::TYPE_REFUND,
            'direction' => PaymentLedger::DIRECTION_CREDIT,
            'amount_minor' => (int)$refund->getData(
                PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR,
            ),
            'currency' => (string)$refund->getData(PaymentRefund::schema_fields_CURRENCY),
            'precision' => (int)$refund->getData(PaymentRefund::schema_fields_PRECISION),
            'transaction_code' => (string)$refund->getData(
                PaymentRefund::schema_fields_TRANSACTION_CODE,
            ),
            'intent_code' => (string)$refund->getData(PaymentRefund::schema_fields_INTENT_CODE),
            'attempt_code' => (string)$refund->getData(PaymentRefund::schema_fields_ATTEMPT_CODE),
            'linked_attempt_id' => $refund->getData(
                PaymentRefund::schema_fields_LINKED_ATTEMPT_ID,
            ),
            'refund_code' => $refundCode,
            'method_code' => (string)$refund->getData(PaymentRefund::schema_fields_METHOD_CODE),
            'provider_code' => (string)$refund->getData(PaymentRefund::schema_fields_PROVIDER_CODE),
            'merchant_account' => (string)$refund->getData(
                PaymentRefund::schema_fields_MERCHANT_ACCOUNT,
            ),
            'payable_type' => (string)$refund->getData(PaymentRefund::schema_fields_PAYABLE_TYPE),
            'payable_id' => (string)$refund->getData(PaymentRefund::schema_fields_PAYABLE_ID),
            'metadata' => [
                'provider_refund_id' => (string)$refund->getData(
                    PaymentRefund::schema_fields_PROVIDER_REFUND_ID,
                ),
                'external_observed' => $lateSuccess,
                'refund_case_uuid' => (string)$refund->getData(
                    PaymentRefund::schema_fields_REFUND_CASE_UUID,
                ),
            ],
        ]);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T[]
     */
    private function collectionItems(mixed $collection, string $className): array
    {
        if (\is_object($collection) && method_exists($collection, 'getItems')) {
            $collection = $collection->getItems();
        }
        if (!\is_array($collection)) {
            return [];
        }

        return array_values(array_filter($collection, static fn (mixed $item): bool => $item instanceof $className));
    }
}
