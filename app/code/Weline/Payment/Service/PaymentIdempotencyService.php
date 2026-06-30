<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentIdempotency;

class PaymentIdempotencyService
{
    public const STATE_EXECUTE = 'execute';
    public const STATE_REPLAY = 'replay';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_FAILED = 'failed';

    private const DEFAULT_ENVIRONMENT = 'sandbox';
    private const DEFAULT_MERCHANT_ACCOUNT = 'default';
    private const DEFAULT_PAYABLE_TYPE = 'order';

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @param array<string, mixed>|string $requestFingerprintSource
     * @param array<string, mixed> $context
     * @return array{state:string,should_execute:bool,record:PaymentIdempotency,result:?array,failure:?array}
     */
    public function begin(
        string $idempotencyKey,
        string $payableId,
        string $methodCode,
        string $operation,
        array|string $requestFingerprintSource = [],
        array $context = [],
        int $ttlSeconds = 86400
    ): array {
        $scope = $this->normalizeScope($idempotencyKey, $payableId, $methodCode, $operation, $context);
        $fingerprint = $this->buildRequestFingerprint($requestFingerprintSource);
        $now = $this->now();
        $record = $this->loadByScopeHash($scope['scope_hash']);

        if ($record) {
            $this->assertFingerprintMatches($record, $fingerprint);

            $status = (string) $record->getData(PaymentIdempotency::schema_fields_STATUS);
            if ($status === PaymentIdempotency::STATUS_SUCCEEDED) {
                return $this->beginResponse(self::STATE_REPLAY, false, $record, $record->getResultPayload(), null);
            }
            if ($status === PaymentIdempotency::STATUS_FAILED) {
                return $this->beginResponse(self::STATE_FAILED, false, $record, null, $record->getFailurePayload());
            }
            if (!$this->isExpired($record, $now)) {
                return $this->beginResponse(self::STATE_IN_PROGRESS, false, $record, null, null);
            }
        }

        $record ??= $this->newIdempotencyModel();
        $this->writeStartedRecord($record, $scope, $fingerprint, $now, $this->secondsFromNow($ttlSeconds));

        try {
            $record->save();
        } catch (\Throwable $throwable) {
            $existing = $this->loadByScopeHash($scope['scope_hash']);
            if ($existing) {
                $this->assertFingerprintMatches($existing, $fingerprint);

                return $this->beginResponse(self::STATE_IN_PROGRESS, false, $existing, null, null);
            }

            throw $throwable;
        }

        return $this->beginResponse(self::STATE_EXECUTE, true, $record, null, null);
    }

    /**
     * @param array<string, mixed> $resultPayload
     * @param array<string, mixed>|string|null $requestFingerprintSource
     * @param array<string, mixed> $context
     */
    public function complete(
        string $idempotencyKey,
        string $payableId,
        string $methodCode,
        string $operation,
        array $resultPayload,
        array|string|null $requestFingerprintSource = null,
        array $context = []
    ): PaymentIdempotency {
        $scope = $this->normalizeScope($idempotencyKey, $payableId, $methodCode, $operation, $context);
        $record = $this->loadRequired($scope['scope_hash']);
        if ($requestFingerprintSource !== null) {
            $this->assertFingerprintMatches($record, $this->buildRequestFingerprint($requestFingerprintSource));
        }

        $status = (string) $record->getData(PaymentIdempotency::schema_fields_STATUS);
        if ($status === PaymentIdempotency::STATUS_SUCCEEDED) {
            return $record;
        }
        if ($status === PaymentIdempotency::STATUS_FAILED) {
            throw new \RuntimeException('payment_idempotency_already_failed');
        }

        $now = $this->now();
        $record->setData(PaymentIdempotency::schema_fields_STATUS, PaymentIdempotency::STATUS_SUCCEEDED)
            ->setData(PaymentIdempotency::schema_fields_FAILURE_PAYLOAD, null)
            ->setData(PaymentIdempotency::schema_fields_FAILURE_REASON_CODE, null)
            ->setData(PaymentIdempotency::schema_fields_FAILED_AT, null)
            ->setData(PaymentIdempotency::schema_fields_COMPLETED_AT, $now)
            ->setData(PaymentIdempotency::schema_fields_UPDATED_AT, $now)
            ->setResultPayload($resultPayload)
            ->save();

        return $record;
    }

    /**
     * @param array<string, mixed> $failurePayload
     * @param array<string, mixed>|string|null $requestFingerprintSource
     * @param array<string, mixed> $context
     */
    public function fail(
        string $idempotencyKey,
        string $payableId,
        string $methodCode,
        string $operation,
        string $failureReasonCode,
        array $failurePayload = [],
        array|string|null $requestFingerprintSource = null,
        array $context = []
    ): PaymentIdempotency {
        $scope = $this->normalizeScope($idempotencyKey, $payableId, $methodCode, $operation, $context);
        $record = $this->loadRequired($scope['scope_hash']);
        if ($requestFingerprintSource !== null) {
            $this->assertFingerprintMatches($record, $this->buildRequestFingerprint($requestFingerprintSource));
        }

        $status = (string) $record->getData(PaymentIdempotency::schema_fields_STATUS);
        if ($status === PaymentIdempotency::STATUS_SUCCEEDED) {
            throw new \RuntimeException('payment_idempotency_already_completed');
        }
        if ($status === PaymentIdempotency::STATUS_FAILED) {
            return $record;
        }

        $now = $this->now();
        $record->setData(PaymentIdempotency::schema_fields_STATUS, PaymentIdempotency::STATUS_FAILED)
            ->setData(PaymentIdempotency::schema_fields_RESULT_PAYLOAD, null)
            ->setData(PaymentIdempotency::schema_fields_COMPLETED_AT, null)
            ->setData(PaymentIdempotency::schema_fields_FAILURE_REASON_CODE, $this->normalizeCode($failureReasonCode))
            ->setData(PaymentIdempotency::schema_fields_FAILED_AT, $now)
            ->setData(PaymentIdempotency::schema_fields_UPDATED_AT, $now)
            ->setFailurePayload($failurePayload)
            ->save();

        return $record;
    }

    /**
     * @param array<string, mixed>|string $requestFingerprintSource
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function getReplayResult(
        string $idempotencyKey,
        string $payableId,
        string $methodCode,
        string $operation,
        array|string $requestFingerprintSource = [],
        array $context = []
    ): ?array {
        $scope = $this->normalizeScope($idempotencyKey, $payableId, $methodCode, $operation, $context);
        $record = $this->loadByScopeHash($scope['scope_hash']);
        if (!$record) {
            return null;
        }

        $this->assertFingerprintMatches($record, $this->buildRequestFingerprint($requestFingerprintSource));
        if ((string) $record->getData(PaymentIdempotency::schema_fields_STATUS) !== PaymentIdempotency::STATUS_SUCCEEDED) {
            return null;
        }

        return $record->getResultPayload();
    }

    private function newIdempotencyModel(): PaymentIdempotency
    {
        /** @var PaymentIdempotency $record */
        $record = $this->objectManager->getInstance(PaymentIdempotency::class);

        return $record;
    }

    private function loadByScopeHash(string $scopeHash): ?PaymentIdempotency
    {
        $record = $this->newIdempotencyModel();
        $record->load(PaymentIdempotency::schema_fields_IDEMPOTENCY_SCOPE_HASH, $scopeHash);

        return $record->getId() ? $record : null;
    }

    private function loadRequired(string $scopeHash): PaymentIdempotency
    {
        $record = $this->loadByScopeHash($scopeHash);
        if (!$record) {
            throw new \RuntimeException('payment_idempotency_record_missing');
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function normalizeScope(
        string $idempotencyKey,
        string $payableId,
        string $methodCode,
        string $operation,
        array $context
    ): array {
        $scope = [
            'environment' => $this->normalizeCode((string) ($context['environment'] ?? self::DEFAULT_ENVIRONMENT)),
            'merchant_account' => $this->normalizeValue((string) ($context['merchant_account'] ?? self::DEFAULT_MERCHANT_ACCOUNT)),
            'payable_type' => $this->normalizeCode((string) ($context['payable_type'] ?? self::DEFAULT_PAYABLE_TYPE)),
            'payable_id' => $this->normalizeValue($payableId),
            'method_code' => $this->normalizeCode($methodCode),
            'operation' => $this->normalizeCode($operation),
            'idempotency_key' => $this->normalizeValue($idempotencyKey),
        ];

        foreach (['idempotency_key', 'payable_id', 'method_code', 'operation'] as $required) {
            if ($scope[$required] === '') {
                throw new \InvalidArgumentException('payment_idempotency_' . $required . '_required');
            }
        }

        $scope['scope_hash'] = hash('sha256', implode('|', [
            $scope['environment'],
            $scope['merchant_account'],
            $scope['method_code'],
            $scope['operation'],
            $scope['payable_type'],
            $scope['payable_id'],
            $scope['idempotency_key'],
        ]));

        return $scope;
    }

    /**
     * @param array<string, string> $scope
     */
    private function writeStartedRecord(
        PaymentIdempotency $record,
        array $scope,
        string $fingerprint,
        string $now,
        string $expiresAt
    ): void {
        $record->setData(PaymentIdempotency::schema_fields_IDEMPOTENCY_SCOPE_HASH, $scope['scope_hash'])
            ->setData(PaymentIdempotency::schema_fields_IDEMPOTENCY_KEY, $scope['idempotency_key'])
            ->setData(PaymentIdempotency::schema_fields_REQUEST_FINGERPRINT, $fingerprint)
            ->setData(PaymentIdempotency::schema_fields_ENVIRONMENT, $scope['environment'])
            ->setData(PaymentIdempotency::schema_fields_MERCHANT_ACCOUNT, $scope['merchant_account'])
            ->setData(PaymentIdempotency::schema_fields_PAYABLE_TYPE, $scope['payable_type'])
            ->setData(PaymentIdempotency::schema_fields_PAYABLE_ID, $scope['payable_id'])
            ->setData(PaymentIdempotency::schema_fields_METHOD_CODE, $scope['method_code'])
            ->setData(PaymentIdempotency::schema_fields_OPERATION, $scope['operation'])
            ->setData(PaymentIdempotency::schema_fields_STATUS, PaymentIdempotency::STATUS_STARTED)
            ->setData(PaymentIdempotency::schema_fields_RESULT_PAYLOAD, null)
            ->setData(PaymentIdempotency::schema_fields_FAILURE_PAYLOAD, null)
            ->setData(PaymentIdempotency::schema_fields_FAILURE_REASON_CODE, null)
            ->setData(PaymentIdempotency::schema_fields_COMPLETED_AT, null)
            ->setData(PaymentIdempotency::schema_fields_FAILED_AT, null)
            ->setData(PaymentIdempotency::schema_fields_EXPIRES_AT, $expiresAt)
            ->setData(PaymentIdempotency::schema_fields_UPDATED_AT, $now);

        if (!$record->getId()) {
            $record->setData(PaymentIdempotency::schema_fields_CREATED_AT, $now);
        }
    }

    private function assertFingerprintMatches(PaymentIdempotency $record, string $fingerprint): void
    {
        if ((string) $record->getData(PaymentIdempotency::schema_fields_REQUEST_FINGERPRINT) !== $fingerprint) {
            throw new \RuntimeException('payment_idempotency_conflict');
        }
    }

    /**
     * @param array<string, mixed>|string $source
     */
    private function buildRequestFingerprint(array|string $source): string
    {
        if (\is_string($source)) {
            $source = trim($source);
            if ($source !== '' && preg_match('/^[a-f0-9]{64}$/i', $source)) {
                return strtolower($source);
            }

            return hash('sha256', $source);
        }

        $json = json_encode($this->canonicalize($source), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', \is_string($json) ? $json : '[]');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            if (\is_scalar($value) || $value === null) {
                return $value;
            }

            return (string) $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function isExpired(PaymentIdempotency $record, string $now): bool
    {
        return strtotime((string) $record->getData(PaymentIdempotency::schema_fields_EXPIRES_AT)) <= strtotime($now);
    }

    /**
     * @return array{state:string,should_execute:bool,record:PaymentIdempotency,result:?array,failure:?array}
     */
    private function beginResponse(
        string $state,
        bool $shouldExecute,
        PaymentIdempotency $record,
        ?array $result,
        ?array $failure
    ): array {
        return [
            'state' => $state,
            'should_execute' => $shouldExecute,
            'record' => $record,
            'result' => $result,
            'failure' => $failure,
        ];
    }

    private function normalizeCode(string $value): string
    {
        return strtolower($this->normalizeValue($value));
    }

    private function normalizeValue(string $value): string
    {
        return trim($value);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function secondsFromNow(int $ttlSeconds): string
    {
        return date('Y-m-d H:i:s', time() + max(1, $ttlSeconds));
    }
}
