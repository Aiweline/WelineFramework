<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Model\Query\FrontendWorkerCredential;
use Weline\Framework\Model\Query\FrontendWorkerCredentialGuard;
use Weline\Framework\Service\Query\FrontendQueryException;

/** @internal Created only inside DatabaseFrontendWorkerCredentialStore::transaction(). */
final class DatabaseFrontendWorkerCredentialTransaction implements FrontendWorkerCredentialTransactionInterface
{
    private const EXPIRED_DELETE_BATCH = 256;
    private const STATE_ACTIVE = 'active';
    private const STATE_CONSUMED = 'consumed';

    private ?int $databaseNow = null;

    public function __construct(
        private readonly ConnectionFactory $connection,
        private readonly FrontendWorkerCredential $credentialPrototype,
        private readonly FrontendWorkerCredentialGuard $guardPrototype,
        private readonly FrontendWorkerCredentialCipher $cipher,
        private readonly string $databaseType,
    ) {
    }

    public function now(): int
    {
        if ($this->databaseNow !== null) {
            return $this->databaseNow;
        }
        $sql = match ($this->databaseType) {
            'mysql', 'mariadb' => 'SELECT UNIX_TIMESTAMP()',
            'pgsql', 'postgres', 'postgresql' => 'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()))::BIGINT',
            'sqlite', 'sqlite3' => "SELECT CAST(strftime('%s','now') AS INTEGER)",
            default => throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential database driver is unsupported.',
                503,
            ),
        };
        $statement = $this->connection->getConnector()->getWrappedConnection()->prepare($sql);
        if (!$statement->execute()) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential database clock is unavailable.',
                503,
            );
        }
        $value = $statement->fetchColumn();
        $timestamp = \is_numeric($value) ? (int)$value : 0;
        if ($timestamp < 1) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential database clock is invalid.',
                503,
            );
        }
        $this->databaseNow = $timestamp;
        return $timestamp;
    }

    public function find(
        string $type,
        string $credential,
        ?string $scope,
        int $now,
    ): ?array {
        $identity = $this->identity($type, $credential, $scope);
        $row = $this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, $identity['type'])
            ->where(FrontendWorkerCredential::schema_fields_SCOPE_HASH, $identity['scope_hash'])
            ->where(FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH, $identity['credential_hash'])
            ->where(FrontendWorkerCredential::schema_fields_STATE, self::STATE_ACTIVE)
            ->where(FrontendWorkerCredential::schema_fields_EXPIRES_AT, $now, '>');
        if ($this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        if (!$row->hasData(FrontendWorkerCredential::schema_fields_ID)) {
            return null;
        }

        $createdAt = (int)$row->getData(FrontendWorkerCredential::schema_fields_CREATED_AT);
        $expiresAt = (int)$row->getData(FrontendWorkerCredential::schema_fields_EXPIRES_AT);
        $storedIdentity = [
            ...$identity,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
        return $this->cipher->decrypt(
            (string)$row->getData(FrontendWorkerCredential::schema_fields_KEY_ID),
            (string)$row->getData(FrontendWorkerCredential::schema_fields_CIPHERTEXT),
            $storedIdentity,
        );
    }

    public function insert(
        string $type,
        string $credential,
        ?string $scope,
        array $payload,
        int $createdAt,
        int $expiresAt,
    ): void {
        if ($credential === '' || $createdAt < 1 || $expiresAt <= $createdAt) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential record is invalid.',
                503,
            );
        }
        $identity = [
            ...$this->identity($type, $credential, $scope),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
        $encrypted = $this->cipher->encrypt($payload, $identity);
        $byteLimit = FrontendWorkerCredentialType::retainedByteLimit($type);
        if ($byteLimit !== null
            && $this->retainedBytes($type, $scope, $createdAt) + $encrypted['payload_bytes'] > $byteLimit) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Worker stream ticket storage capacity is exhausted.',
                503,
            );
        }
        $this->credentialModel()->setData([
            FrontendWorkerCredential::schema_fields_TYPE => $identity['type'],
            FrontendWorkerCredential::schema_fields_SCOPE_HASH => $identity['scope_hash'],
            FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH => $identity['credential_hash'],
            FrontendWorkerCredential::schema_fields_KEY_ID => $encrypted['key_id'],
            FrontendWorkerCredential::schema_fields_CIPHERTEXT => $encrypted['ciphertext'],
            FrontendWorkerCredential::schema_fields_PAYLOAD_BYTES => $encrypted['payload_bytes'],
            FrontendWorkerCredential::schema_fields_STATE => self::STATE_ACTIVE,
            FrontendWorkerCredential::schema_fields_CONSUMED_AT => 0,
            FrontendWorkerCredential::schema_fields_LOCK_VERSION => 0,
            FrontendWorkerCredential::schema_fields_CREATED_AT => $createdAt,
            FrontendWorkerCredential::schema_fields_EXPIRES_AT => $expiresAt,
        ])->save();
    }

    public function consume(string $type, string $credential, ?string $scope): bool
    {
        $identity = $this->identity($type, $credential, $scope);
        $row = $this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, $identity['type'])
            ->where(FrontendWorkerCredential::schema_fields_SCOPE_HASH, $identity['scope_hash'])
            ->where(FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH, $identity['credential_hash'])
            ->where(FrontendWorkerCredential::schema_fields_STATE, self::STATE_ACTIVE);
        if ($this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        $id = (int)$row->getData(FrontendWorkerCredential::schema_fields_ID);
        if ($id < 1) {
            return false;
        }
        $lockVersion = (int)$row->getData(FrontendWorkerCredential::schema_fields_LOCK_VERSION);
        $consumedAt = $this->now();
        $row->setData(FrontendWorkerCredential::schema_fields_STATE, self::STATE_CONSUMED)
            ->setData(FrontendWorkerCredential::schema_fields_CONSUMED_AT, $consumedAt)
            ->setData(FrontendWorkerCredential::schema_fields_LOCK_VERSION, $lockVersion + 1)
            ->save();

        // Some adapters report a falsey UPDATE result even after a successful
        // write. The locked same-connection reread is the portable authority.
        $committed = $this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_ID, $id)
            ->find()
            ->fetch();
        if ((string)$committed->getData(FrontendWorkerCredential::schema_fields_STATE) !== self::STATE_CONSUMED
            || (int)$committed->getData(FrontendWorkerCredential::schema_fields_LOCK_VERSION) !== $lockVersion + 1
            || (int)$committed->getData(FrontendWorkerCredential::schema_fields_CONSUMED_AT) !== $consumedAt) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential consumption could not be verified.',
                503,
            );
        }
        return true;
    }

    public function countRetained(string $type, ?string $scope, int $now): int
    {
        $scopeHash = $this->lockCapacityScope($type, $scope, $now);

        return (int)$this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, $type)
            ->where(FrontendWorkerCredential::schema_fields_SCOPE_HASH, $scopeHash)
            ->where(FrontendWorkerCredential::schema_fields_EXPIRES_AT, $now, '>')
            ->count();
    }

    public function countRetainedInCapacityBucket(string $type, ?string $scope, int $now): int
    {
        if ($type !== FrontendWorkerCredentialType::NONCE || !\is_string($scope) || $scope === '') {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential capacity bucket is invalid.',
                503,
            );
        }
        $scopeHash = \hash('sha256', $scope);
        $shard = \substr($scopeHash, 0, 1);
        $this->lockGuard(self::nonceGuardKeyForShard($shard));
        if ($this->find(FrontendWorkerCredentialType::SESSION, $scope, null, $now) === null) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        // Every successful nonce write can add only one row, while this
        // shard-local janitor removes up to EXPIRED_DELETE_BATCH old rows
        // under the same capacity lock. This prevents expired nonce rows
        // from accumulating across abandoned Session scopes.
        $this->deleteExpiredBatch(
            $now,
            FrontendWorkerCredentialType::NONCE,
            null,
            $shard,
        );

        return (int)$this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, FrontendWorkerCredentialType::NONCE)
            ->where(FrontendWorkerCredential::schema_fields_SCOPE_HASH, $shard . '%', 'like')
            ->where(FrontendWorkerCredential::schema_fields_EXPIRES_AT, $now, '>')
            ->count();
    }

    public function retainedBytes(string $type, ?string $scope, int $now): int
    {
        $scopeHash = $this->lockCapacityScope($type, $scope, $now);
        $rows = $this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, $type)
            ->where(FrontendWorkerCredential::schema_fields_SCOPE_HASH, $scopeHash)
            ->where(FrontendWorkerCredential::schema_fields_EXPIRES_AT, $now, '>')
            ->select(
                'COALESCE(SUM(LENGTH(main_table.'
                . FrontendWorkerCredential::schema_fields_CIPHERTEXT
                . ')), 0) AS retained_bytes',
            )
            ->fetchArray();
        return \max(0, (int)($rows[0]['retained_bytes'] ?? 0));
    }

    public function deleteExpired(int $now, ?string $type = null, ?string $scope = null): void
    {
        if ($type !== null) {
            FrontendWorkerCredentialType::assert($type);
        }
        if ($scope !== null && ($type !== FrontendWorkerCredentialType::NONCE || $scope === '')) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential cleanup scope is invalid.',
                503,
            );
        }

        $types = $type === null ? FrontendWorkerCredentialType::all() : [$type];
        foreach ($types as $currentType) {
            $this->deleteExpiredBatch($now, $currentType, $scope);
        }
    }

    private function deleteExpiredBatch(
        int $now,
        string $type,
        ?string $scope,
        ?string $scopeHashPrefix = null,
    ): void
    {
        $query = $this->credentialModel()
            ->where(FrontendWorkerCredential::schema_fields_TYPE, $type)
            ->where(FrontendWorkerCredential::schema_fields_EXPIRES_AT, $now, '<=')
            ->order(FrontendWorkerCredential::schema_fields_EXPIRES_AT, 'ASC')
            ->order(FrontendWorkerCredential::schema_fields_ID, 'ASC')
            ->limit(self::EXPIRED_DELETE_BATCH);
        if ($scopeHashPrefix !== null) {
            if ($type !== FrontendWorkerCredentialType::NONCE
                || \preg_match('/^[a-f0-9]$/D', $scopeHashPrefix) !== 1) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential cleanup shard is invalid.',
                    503,
                );
            }
            $query->where(
                FrontendWorkerCredential::schema_fields_SCOPE_HASH,
                $scopeHashPrefix . '%',
                'like',
            );
        } elseif ($scope !== null) {
            $query->where(
                FrontendWorkerCredential::schema_fields_SCOPE_HASH,
                \hash('sha256', $scope),
            );
        }
        if ($this->supportsForUpdate()) {
            $query->additional('FOR UPDATE');
        }
        $rows = $query->select(FrontendWorkerCredential::schema_fields_ID)->fetchArray();
        $ids = [];
        foreach ((array)$rows as $row) {
            $id = \is_array($row) ? (int)($row[FrontendWorkerCredential::schema_fields_ID] ?? 0) : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids !== []) {
            $this->credentialModel()
                ->where(FrontendWorkerCredential::schema_fields_ID, \array_values($ids), 'in')
                ->delete();
        }
    }

    private function lockCapacityScope(string $type, ?string $scope, int $now): string
    {
        FrontendWorkerCredentialType::assert($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            if (!\is_string($scope) || $scope === '') {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker nonce scope is invalid.',
                    503,
                );
            }
            // The active Session row is the nonce-capacity mutex. This also
            // makes an orphan nonce write impossible.
            if ($this->find(FrontendWorkerCredentialType::SESSION, $scope, null, $now) === null) {
                throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
            }
            return \hash('sha256', $scope);
        }
        if ($scope !== null) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential scope is invalid.',
                503,
            );
        }
        $this->lockGuard(self::guardKey($type));
        return '';
    }

    private function lockGuard(string $bucketKey): void
    {
        $guard = $this->guardModel()
            ->where(FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY, $bucketKey);
        if ($this->supportsForUpdate()) {
            $guard->additional('FOR UPDATE');
        }
        $guard->find()->fetch();
        if (!$guard->hasData(FrontendWorkerCredentialGuard::schema_fields_ID)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential capacity guard is unavailable.',
                503,
            );
        }
    }

    /** @return array{type:string,scope_hash:string,credential_hash:string} */
    private function identity(string $type, string $credential, ?string $scope): array
    {
        FrontendWorkerCredentialType::assert($type);
        if ($credential === '') {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential identity is invalid.',
                503,
            );
        }
        if ($type === FrontendWorkerCredentialType::NONCE) {
            if (!\is_string($scope) || $scope === '') {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker nonce scope is invalid.',
                    503,
                );
            }
            $scopeHash = \hash('sha256', $scope);
        } else {
            if ($scope !== null) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential scope is invalid.',
                    503,
                );
            }
            $scopeHash = '';
        }

        return [
            'type' => $type,
            'scope_hash' => $scopeHash,
            'credential_hash' => \hash('sha256', $type . "\0" . $scopeHash . "\0" . $credential),
        ];
    }

    private function credentialModel(): FrontendWorkerCredential
    {
        $model = clone $this->credentialPrototype;
        return $model->setConnection($this->connection)->clearData()->clearQuery();
    }

    private function guardModel(): FrontendWorkerCredentialGuard
    {
        $model = clone $this->guardPrototype;
        return $model->setConnection($this->connection)->clearData()->clearQuery();
    }

    private function supportsForUpdate(): bool
    {
        return \in_array($this->databaseType, [
            'mysql',
            'mariadb',
            'pgsql',
            'postgres',
            'postgresql',
        ], true);
    }

    public static function guardKey(string $type): string
    {
        FrontendWorkerCredentialType::assert($type);
        return 'worker-credential-v1:' . $type;
    }

    public static function nonceGuardKeyForShard(string $shard): string
    {
        $shard = \strtolower(\trim($shard));
        if (\preg_match('/^[a-f0-9]$/D', $shard) !== 1) {
            throw new \InvalidArgumentException('Invalid Worker nonce capacity shard.');
        }
        return 'worker-credential-v1:nonce-shard:' . $shard;
    }
}
