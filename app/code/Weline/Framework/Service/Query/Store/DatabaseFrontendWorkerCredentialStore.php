<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Model\Query\FrontendWorkerCredential;
use Weline\Framework\Model\Query\FrontendWorkerCredentialGuard;
use Weline\Framework\Service\Query\FrontendQueryException;

/**
 * Main-database Worker credential authority.
 *
 * Every callback runs on one write-intent transaction. MySQL/PostgreSQL use
 * row locks; SQLite uses BEGIN IMMEDIATE and is never reported as shared.
 */
final class DatabaseFrontendWorkerCredentialStore implements FrontendWorkerCredentialStoreInterface
{
    private const GUARDED_TYPES = [
        FrontendWorkerCredentialType::SESSION,
        FrontendWorkerCredentialType::SCOPE_BOOTSTRAP,
        FrontendWorkerCredentialType::BACKEND_BOOTSTRAP,
        FrontendWorkerCredentialType::STREAM_TICKET,
    ];

    private const NONCE_GUARD_SHARDS = [
        '0', '1', '2', '3', '4', '5', '6', '7',
        '8', '9', 'a', 'b', 'c', 'd', 'e', 'f',
    ];

    private const REQUIRED_CREDENTIAL_COLUMNS = [
        FrontendWorkerCredential::schema_fields_ID,
        FrontendWorkerCredential::schema_fields_TYPE,
        FrontendWorkerCredential::schema_fields_SCOPE_HASH,
        FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH,
        FrontendWorkerCredential::schema_fields_KEY_ID,
        FrontendWorkerCredential::schema_fields_CIPHERTEXT,
        FrontendWorkerCredential::schema_fields_PAYLOAD_BYTES,
        FrontendWorkerCredential::schema_fields_STATE,
        FrontendWorkerCredential::schema_fields_CONSUMED_AT,
        FrontendWorkerCredential::schema_fields_LOCK_VERSION,
        FrontendWorkerCredential::schema_fields_CREATED_AT,
        FrontendWorkerCredential::schema_fields_EXPIRES_AT,
    ];

    private const REQUIRED_GUARD_COLUMNS = [
        FrontendWorkerCredentialGuard::schema_fields_ID,
        FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY,
        FrontendWorkerCredentialGuard::schema_fields_CREATED_AT,
    ];

    private const REQUIRED_CREDENTIAL_INDEXES = [
        ['columns' => [
            FrontendWorkerCredential::schema_fields_TYPE,
            FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH,
        ], 'unique' => true],
        ['columns' => [
            FrontendWorkerCredential::schema_fields_EXPIRES_AT,
            FrontendWorkerCredential::schema_fields_ID,
        ], 'unique' => false],
        ['columns' => [
            FrontendWorkerCredential::schema_fields_TYPE,
            FrontendWorkerCredential::schema_fields_EXPIRES_AT,
            FrontendWorkerCredential::schema_fields_ID,
        ], 'unique' => false],
        ['columns' => [
            FrontendWorkerCredential::schema_fields_TYPE,
            FrontendWorkerCredential::schema_fields_SCOPE_HASH,
            FrontendWorkerCredential::schema_fields_EXPIRES_AT,
            FrontendWorkerCredential::schema_fields_ID,
        ], 'unique' => false],
    ];

    private const REQUIRED_GUARD_INDEXES = [
        ['columns' => [FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY], 'unique' => true],
    ];

    private bool $guardsReady = false;
    private readonly ConnectionFactory $connection;
    private readonly string $databaseType;

    public function __construct(
        private readonly FrontendWorkerCredential $credentialPrototype,
        private readonly FrontendWorkerCredentialGuard $guardPrototype,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
        private readonly FrontendWorkerCredentialCipher $cipher,
    ) {
        $this->connection = $this->credentialPrototype->getConnection();
        $this->databaseType = \strtolower(\trim((string)$this->connection
            ->getConfigProvider()
            ->getDbType()));
        if (!\in_array($this->databaseType, [
            'mysql',
            'mariadb',
            'pgsql',
            'postgres',
            'postgresql',
            'sqlite',
            'sqlite3',
        ], true)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential database driver is unsupported.',
                503,
            );
        }
    }

    public function transaction(callable $callback): mixed
    {
        try {
            $this->ensureGuards();
            return $this->transactions->runWrite(
                $this->connection,
                function () use ($callback): mixed {
                    $transaction = new DatabaseFrontendWorkerCredentialTransaction(
                        $this->connection,
                        $this->credentialPrototype,
                        $this->guardPrototype,
                        $this->cipher,
                        $this->databaseType,
                    );
                    return $callback($transaction);
                },
            );
        } catch (FrontendQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Shared worker credential store is unavailable.',
                503,
                $exception,
            );
        }
    }

    public function driver(): string
    {
        return 'database:' . $this->databaseType;
    }

    public function isShared(): bool
    {
        return \in_array($this->databaseType, [
            'mysql',
            'mariadb',
            'pgsql',
            'postgres',
            'postgresql',
        ], true);
    }

    /**
     * @return array{
     *     driver:string,
     *     shared:bool,
     *     schema_version:int,
     *     guard_count:int,
     *     credential_column_count:int,
     *     credential_index_count:int,
     *     guard_column_count:int,
     *     guard_index_count:int,
     *     writable:bool,
     *     keyring_version:int,
     *     keyring_digest:string
     * }
     */
    public function diagnostics(): array
    {
        try {
            $schema = $this->assertSchemaReady();
            $this->ensureGuards();
            $guardCount = $this->assertRequiredGuardsReady();
            $this->assertCredentialRoundTripWritable();
            return [
                'driver' => $this->driver(),
                'shared' => $this->isShared(),
                'schema_version' => 1,
                'guard_count' => $guardCount,
                'credential_column_count' => $schema['credential_column_count'],
                'credential_index_count' => $schema['credential_index_count'],
                'guard_column_count' => $schema['guard_column_count'],
                'guard_index_count' => $schema['guard_index_count'],
                'writable' => true,
                'keyring_version' => $this->cipher->version(),
                'keyring_digest' => $this->cipher->digest(),
            ];
        } catch (FrontendQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential store readiness verification failed.',
                503,
                $exception,
            );
        }
    }

    /**
     * @return array{
     *     credential_column_count:int,
     *     credential_index_count:int,
     *     guard_column_count:int,
     *     guard_index_count:int
     * }
     */
    private function assertSchemaReady(): array
    {
        $connector = $this->connection->getConnector();
        $credentialColumns = $connector->getTableColumns(FrontendWorkerCredential::schema_table);
        $guardColumns = $connector->getTableColumns(FrontendWorkerCredentialGuard::schema_table);
        $credentialIndexes = $connector->getTableIndexes(FrontendWorkerCredential::schema_table);
        $guardIndexes = $connector->getTableIndexes(FrontendWorkerCredentialGuard::schema_table);

        $this->assertRequiredColumns($credentialColumns, self::REQUIRED_CREDENTIAL_COLUMNS);
        $this->assertRequiredColumns($guardColumns, self::REQUIRED_GUARD_COLUMNS);
        $this->assertRequiredIndexes($credentialIndexes, self::REQUIRED_CREDENTIAL_INDEXES);
        $this->assertRequiredIndexes($guardIndexes, self::REQUIRED_GUARD_INDEXES);

        return [
            'credential_column_count' => \count(self::REQUIRED_CREDENTIAL_COLUMNS),
            'credential_index_count' => \count(self::REQUIRED_CREDENTIAL_INDEXES),
            'guard_column_count' => \count(self::REQUIRED_GUARD_COLUMNS),
            'guard_index_count' => \count(self::REQUIRED_GUARD_INDEXES),
        ];
    }

    /** @param list<array<string, mixed>> $actual @param list<string> $required */
    private function assertRequiredColumns(array $actual, array $required): void
    {
        $names = [];
        foreach ($actual as $column) {
            $name = \strtolower(\trim((string)($column['name'] ?? '')));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        foreach ($required as $column) {
            if (!isset($names[\strtolower($column)])) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential store schema is incomplete.',
                    503,
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $actual
     * @param list<array{columns:list<string>,unique:bool}> $required
     */
    private function assertRequiredIndexes(array $actual, array $required): void
    {
        $normalized = [];
        foreach ($actual as $index) {
            $columns = \array_values(\array_map(
                static fn(mixed $column): string => \strtolower(\trim((string)$column)),
                (array)($index['columns'] ?? []),
            ));
            $normalized[] = [
                'columns' => $columns,
                'unique' => (bool)($index['unique'] ?? false),
            ];
        }
        foreach ($required as $expected) {
            $expectedColumns = \array_map('strtolower', $expected['columns']);
            $found = false;
            foreach ($normalized as $index) {
                if ($index['columns'] === $expectedColumns && $index['unique'] === $expected['unique']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential store indexes are incomplete.',
                    503,
                );
            }
        }
    }

    private function assertRequiredGuardsReady(): int
    {
        $rows = $this->guardModel()
            ->select(FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY)
            ->fetchArray();
        $actual = [];
        foreach ((array)$rows as $row) {
            $bucket = \is_array($row)
                ? (string)($row[FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY] ?? '')
                : '';
            if ($bucket !== '') {
                $actual[$bucket] = true;
            }
        }
        $required = $this->requiredGuardKeys();
        foreach ($required as $bucket) {
            if (!isset($actual[$bucket])) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential capacity guard is unavailable.',
                    503,
                );
            }
        }
        return \count($required);
    }

    private function assertCredentialRoundTripWritable(): void
    {
        $this->transactions->runWrite($this->connection, function (): void {
            $transaction = new DatabaseFrontendWorkerCredentialTransaction(
                $this->connection,
                $this->credentialPrototype,
                $this->guardPrototype,
                $this->cipher,
                $this->databaseType,
            );
            $now = $transaction->now();
            $credential = 'readiness-probe-' . \bin2hex(\random_bytes(24));
            $credentialHash = \hash(
                'sha256',
                FrontendWorkerCredentialType::SESSION . "\0\0" . $credential,
            );
            $payload = ['readiness_probe' => true];
            $transaction->countRetained(
                FrontendWorkerCredentialType::SESSION,
                null,
                $now,
            );
            $transaction->insert(
                FrontendWorkerCredentialType::SESSION,
                $credential,
                null,
                $payload,
                $now,
                $now + 60,
            );
            if ($transaction->find(
                FrontendWorkerCredentialType::SESSION,
                $credential,
                null,
                $now,
            ) !== $payload) {
                throw new \RuntimeException('Worker credential insert/read probe failed.');
            }

            $row = $this->credentialModel()
                ->where(FrontendWorkerCredential::schema_fields_TYPE, FrontendWorkerCredentialType::SESSION)
                ->where(FrontendWorkerCredential::schema_fields_CREDENTIAL_HASH, $credentialHash)
                ->find()
                ->fetch();
            $id = (int)$row->getData(FrontendWorkerCredential::schema_fields_ID);
            if ($id < 1 || !$transaction->consume(
                FrontendWorkerCredentialType::SESSION,
                $credential,
                null,
            )) {
                throw new \RuntimeException('Worker credential update probe failed.');
            }
            $this->credentialModel()
                ->where(FrontendWorkerCredential::schema_fields_ID, $id)
                ->delete();
            $deleted = $this->credentialModel()
                ->where(FrontendWorkerCredential::schema_fields_ID, $id)
                ->find()
                ->fetch();
            if ($deleted->hasData(FrontendWorkerCredential::schema_fields_ID)) {
                throw new \RuntimeException('Worker credential delete probe failed.');
            }
        });
    }

    private function ensureGuards(): void
    {
        if ($this->guardsReady) {
            return;
        }
        foreach ($this->requiredGuardKeys() as $bucket) {
            if (!$this->guardExists($bucket)) {
                $guard = $this->guardModel();
                try {
                    $guard->setData([
                        FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY => $bucket,
                        FrontendWorkerCredentialGuard::schema_fields_CREATED_AT => \time(),
                    ])->save();
                } catch (\Throwable $exception) {
                    if (!$this->uniqueViolation->matches(
                        $exception,
                        'uk_worker_credential_guard_bucket',
                        $guard->getTable(),
                        FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY,
                    )) {
                        throw $exception;
                    }
                }
            }
            if (!$this->guardExists($bucket)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential capacity guard is unavailable.',
                    503,
                );
            }
        }
        $this->guardsReady = true;
    }

    /** @return list<string> */
    private function requiredGuardKeys(): array
    {
        $keys = [];
        foreach (self::GUARDED_TYPES as $type) {
            $keys[] = DatabaseFrontendWorkerCredentialTransaction::guardKey($type);
        }
        foreach (self::NONCE_GUARD_SHARDS as $shard) {
            $keys[] = DatabaseFrontendWorkerCredentialTransaction::nonceGuardKeyForShard($shard);
        }
        return $keys;
    }

    private function guardExists(string $bucket): bool
    {
        $guard = $this->guardModel()
            ->where(FrontendWorkerCredentialGuard::schema_fields_BUCKET_KEY, $bucket)
            ->find()
            ->fetch();
        return $guard->hasData(FrontendWorkerCredentialGuard::schema_fields_ID);
    }

    private function guardModel(): FrontendWorkerCredentialGuard
    {
        $model = clone $this->guardPrototype;
        return $model->setConnection($this->connection)->clearData()->clearQuery();
    }

    private function credentialModel(): FrontendWorkerCredential
    {
        $model = clone $this->credentialPrototype;
        return $model->setConnection($this->connection)->clearData()->clearQuery();
    }
}
