<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentLock;

class PaymentLockService
{
    private const DEFAULT_ENVIRONMENT = 'sandbox';
    private const DEFAULT_MERCHANT_ACCOUNT = 'default';
    private const DEFAULT_PAYABLE_TYPE = 'order';

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    public function acquire(
        string $payableId,
        string $methodCode,
        string $operation,
        string $lockKey,
        array $context = [],
        ?string $ownerToken = null,
        int $ttlSeconds = 300,
        array $metadata = []
    ): PaymentLock {
        $scope = $this->normalizeScope($payableId, $methodCode, $operation, $lockKey, $context);
        $ownerToken = $this->normalizeOwnerToken($ownerToken);
        $now = $this->now();
        $expiresAt = $this->secondsFromNow($ttlSeconds);
        $lock = $this->loadByScopeHash($scope['scope_hash']);

        if ($lock && $this->isActive($lock, $now)) {
            if (!$this->matchesOwnerToken($lock, $ownerToken)) {
                throw new \RuntimeException('payment_lock_already_acquired');
            }

            return $lock;
        }

        $lock ??= $this->newLockModel();
        $this->writeScope($lock, $scope);
        $lock->setData(PaymentLock::schema_fields_OWNER_TOKEN_HASH, $this->hashOwnerToken($ownerToken))
            ->setData(PaymentLock::schema_fields_LOCK_CODE, $lock->getData(PaymentLock::schema_fields_LOCK_CODE) ?: $this->buildLockCode($scope['scope_hash']))
            ->setData(PaymentLock::schema_fields_STATUS, PaymentLock::STATUS_ACQUIRED)
            ->setData(PaymentLock::schema_fields_ACTIVE_FLAG, 1)
            ->setData(PaymentLock::schema_fields_TTL_SECONDS, max(1, $ttlSeconds))
            ->setData(PaymentLock::schema_fields_ACQUIRED_AT, $now)
            ->setData(PaymentLock::schema_fields_EXPIRES_AT, $expiresAt)
            ->setData(PaymentLock::schema_fields_RELEASED_AT, null)
            ->setData(PaymentLock::schema_fields_UPDATED_AT, $now)
            ->setMetadata($metadata);

        if (!$lock->getId()) {
            $lock->setData(PaymentLock::schema_fields_CREATED_AT, $now);
        }

        try {
            $lock->save();
        } catch (\Throwable $throwable) {
            $existing = $this->loadByScopeHash($scope['scope_hash']);
            if ($existing && $this->isActive($existing, $now)) {
                throw new \RuntimeException('payment_lock_already_acquired', 0, $throwable);
            }

            throw $throwable;
        }

        return $lock;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function release(
        string $payableId,
        string $methodCode,
        string $operation,
        string $lockKey,
        string $ownerToken,
        array $context = []
    ): bool {
        $scope = $this->normalizeScope($payableId, $methodCode, $operation, $lockKey, $context);
        $lock = $this->loadByScopeHash($scope['scope_hash']);
        if (!$lock || (string) $lock->getData(PaymentLock::schema_fields_STATUS) !== PaymentLock::STATUS_ACQUIRED) {
            return false;
        }

        if (!$this->matchesOwnerToken($lock, $ownerToken)) {
            throw new \RuntimeException('payment_lock_owner_mismatch');
        }

        $now = $this->now();
        $lock->setData(PaymentLock::schema_fields_STATUS, PaymentLock::STATUS_RELEASED)
            ->setData(PaymentLock::schema_fields_ACTIVE_FLAG, 0)
            ->setData(PaymentLock::schema_fields_RELEASED_AT, $now)
            ->setData(PaymentLock::schema_fields_UPDATED_AT, $now)
            ->save();

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function isLocked(
        string $payableId,
        string $methodCode,
        string $operation,
        string $lockKey,
        array $context = []
    ): bool {
        $scope = $this->normalizeScope($payableId, $methodCode, $operation, $lockKey, $context);
        $lock = $this->loadByScopeHash($scope['scope_hash']);

        return $lock !== null && $this->isActive($lock, $this->now());
    }

    public function releaseExpired(int $limit = 100): int
    {
        $now = $this->now();
        $limit = max(1, min(1000, $limit));
        $lockModel = $this->newLockModel();
        $records = $lockModel->where(PaymentLock::schema_fields_STATUS, PaymentLock::STATUS_ACQUIRED)
            ->where(PaymentLock::schema_fields_EXPIRES_AT, $now, '<=')
            ->select()
            ->limit($limit)
            ->fetch();

        $count = 0;
        foreach ($this->normalizeModelList($records) as $lock) {
            $lock->setData(PaymentLock::schema_fields_STATUS, PaymentLock::STATUS_EXPIRED)
                ->setData(PaymentLock::schema_fields_ACTIVE_FLAG, 0)
                ->setData(PaymentLock::schema_fields_RELEASED_AT, $now)
                ->setData(PaymentLock::schema_fields_UPDATED_AT, $now)
                ->save();
            $count++;
        }

        return $count;
    }

    public function getOwnerTokenHash(PaymentLock $lock): string
    {
        return (string) $lock->getData(PaymentLock::schema_fields_OWNER_TOKEN_HASH);
    }

    private function newLockModel(): PaymentLock
    {
        /** @var PaymentLock $lock */
        $lock = $this->objectManager->getInstance(PaymentLock::class);

        return $lock;
    }

    private function loadByScopeHash(string $scopeHash): ?PaymentLock
    {
        $lock = $this->newLockModel();
        $lock->load(PaymentLock::schema_fields_LOCK_SCOPE_HASH, $scopeHash);

        return $lock->getId() ? $lock : null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function normalizeScope(
        string $payableId,
        string $methodCode,
        string $operation,
        string $lockKey,
        array $context
    ): array {
        $scope = [
            'environment' => $this->normalizeCode((string) ($context['environment'] ?? self::DEFAULT_ENVIRONMENT)),
            'merchant_account' => $this->normalizeValue((string) ($context['merchant_account'] ?? self::DEFAULT_MERCHANT_ACCOUNT)),
            'payable_type' => $this->normalizeCode((string) ($context['payable_type'] ?? self::DEFAULT_PAYABLE_TYPE)),
            'payable_id' => $this->normalizeValue($payableId),
            'method_code' => $this->normalizeCode($methodCode),
            'operation' => $this->normalizeCode($operation),
            'lock_key' => $this->normalizeValue($lockKey),
        ];
        $scope['lock_scope'] = $this->normalizeCode((string) ($context['lock_scope'] ?? $scope['operation']));

        foreach (['payable_id', 'method_code', 'operation', 'lock_key'] as $required) {
            if ($scope[$required] === '') {
                throw new \InvalidArgumentException('payment_lock_' . $required . '_required');
            }
        }

        $scope['scope_hash'] = hash('sha256', implode('|', [
            $scope['environment'],
            $scope['merchant_account'],
            $scope['payable_type'],
            $scope['payable_id'],
            $scope['method_code'],
            $scope['operation'],
            $scope['lock_key'],
        ]));

        return $scope;
    }

    /**
     * @param array<string, string> $scope
     */
    private function writeScope(PaymentLock $lock, array $scope): void
    {
        $lock->setData(PaymentLock::schema_fields_LOCK_SCOPE_HASH, $scope['scope_hash'])
            ->setData(PaymentLock::schema_fields_LOCK_SCOPE, $scope['lock_scope'])
            ->setData(PaymentLock::schema_fields_LOCK_KEY, $scope['lock_key'])
            ->setData(PaymentLock::schema_fields_ENVIRONMENT, $scope['environment'])
            ->setData(PaymentLock::schema_fields_MERCHANT_ACCOUNT, $scope['merchant_account'])
            ->setData(PaymentLock::schema_fields_PAYABLE_TYPE, $scope['payable_type'])
            ->setData(PaymentLock::schema_fields_PAYABLE_ID, $scope['payable_id'])
            ->setData(PaymentLock::schema_fields_METHOD_CODE, $scope['method_code'])
            ->setData(PaymentLock::schema_fields_OPERATION, $scope['operation']);
    }

    private function isActive(PaymentLock $lock, string $now): bool
    {
        if ((string) $lock->getData(PaymentLock::schema_fields_STATUS) !== PaymentLock::STATUS_ACQUIRED) {
            return false;
        }

        return strtotime((string) $lock->getData(PaymentLock::schema_fields_EXPIRES_AT)) > strtotime($now);
    }

    private function normalizeOwnerToken(?string $ownerToken): string
    {
        $ownerToken = trim((string) $ownerToken);
        if ($ownerToken !== '') {
            return $ownerToken;
        }

        return bin2hex(random_bytes(16));
    }

    private function hashOwnerToken(string $ownerToken): string
    {
        return hash('sha256', $ownerToken);
    }

    private function matchesOwnerToken(PaymentLock $lock, string $ownerToken): bool
    {
        return hash_equals(
            (string) $lock->getData(PaymentLock::schema_fields_OWNER_TOKEN_HASH),
            $this->hashOwnerToken($ownerToken)
        );
    }

    private function buildLockCode(string $scopeHash): string
    {
        return 'lock_' . substr($scopeHash, 0, 32);
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

    /**
     * @return PaymentLock[]
     */
    private function normalizeModelList(mixed $records): array
    {
        if (\is_object($records) && method_exists($records, 'getItems')) {
            $records = $records->getItems();
        }
        if (!\is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, static fn (mixed $record): bool => $record instanceof PaymentLock));
    }
}
