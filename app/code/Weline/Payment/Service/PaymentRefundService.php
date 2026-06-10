<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentTransaction;

class PaymentRefundService
{
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
        ],
        PaymentRefund::STATUS_PENDING => [
            PaymentRefund::STATUS_PROCESSING,
            PaymentRefund::STATUS_REFUNDED,
            PaymentRefund::STATUS_FAILED,
            PaymentRefund::STATUS_UNSUPPORTED,
        ],
    ];

    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentLedgerService $ledgerService,
        private readonly ObjectManager $objectManager,
        private ?PaymentScopeConfigService $scopeConfigService = null
    ) {
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
        $this->assertPositiveAmount($requestedAmountMinor, 'payment_refund_requested_amount_required');
        $transaction = $this->requireRefundableTransaction($transactionCode);
        $transactionCode = (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO);
        $currency = $this->resolveTransactionCurrency($transaction, $context);
        $precision = $this->resolveMoneyPrecision($currency, $transaction->getRequestData(), $context);
        $transactionAmountMinor = $this->getTransactionAmountMinor($transaction, $precision);
        $reservedAmountMinor = $this->getReservedRefundAmountMinor($transactionCode);
        $remainingAmountMinor = $transactionAmountMinor - $reservedAmountMinor;

        if ($requestedAmountMinor > $remainingAmountMinor) {
            throw new \LogicException('payment_refund_amount_exceeds_remaining_amount');
        }

        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '') {
            $existing = $this->getRefundByIdempotencyKey($transactionCode, $idempotencyKey);
            if ($existing instanceof PaymentRefund) {
                return $existing;
            }
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
            ->setData(PaymentRefund::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
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
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionCode);

        return $transaction->getId() ? $transaction : null;
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
        return $this->objectManager->getInstance(PaymentRefund::class);
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
