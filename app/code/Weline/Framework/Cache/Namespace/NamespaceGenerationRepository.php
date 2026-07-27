<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Namespace;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Runtime\RequestContext;

/** Database authority for namespace generations and the reserved @clock row. */
final class NamespaceGenerationRepository implements NamespaceGenerationInterface
{
    private const MAX_CAS_ATTEMPTS = 8;
    private const TRANSACTION_STATE_KEY = 'framework.cache.namespace_generation_transactions';

    private ?TransactionCoordinatorInterface $transactions;

    public function __construct(
        private readonly NamespaceVersion $generationModel,
        private readonly NamespacePath $path,
        private readonly NamespaceGenerationSnapshot $snapshot,
        private readonly NamespaceKeyDecorator $decorator,
        ?TransactionCoordinatorInterface $transactions = null,
    ) {
        $this->transactions = $transactions;
    }

    public function canonicalize(string $namespace): string
    {
        return $this->path->canonicalize($namespace);
    }

    /**
     * Namespace generations and their business mutation must share one logical
     * connection. Validate before the generation write; an owner transaction
     * then rolls its earlier business DML back on mismatch. Multi-database cache
     * authority requires a separately keyed snapshot/outbox design.
     */
    public function assertConnectionAffinity(ConnectionFactory $connection): void
    {
        if (self::connectionKey($this->generationModel->getConnection())
            !== self::connectionKey($connection)) {
            throw new \LogicException(__('缓存代际与业务写必须使用同一逻辑数据库连接'));
        }
    }

    /** @param list<string> $namespaces @return list<string> */
    public function canonicalizeMany(array $namespaces): array
    {
        return $this->path->canonicalizeMany($namespaces);
    }

    /**
     * Resolve every unique ancestor in one first-access batch query.
     *
     * @param list<string> $namespaces
     * @return array{authority_clock:int,generations:array<string,int>}
     */
    public function resolveVector(array $namespaces): array
    {
        $ancestors = $this->path->expandAncestors($namespaces);
        return $this->snapshot->resolve(
            $ancestors,
            fn(array $requested): array => $this->readGenerationValues($requested)
        );
    }

    /** @param list<string> $namespaces */
    public function fingerprint(array $namespaces): string
    {
        return $this->decorator->fingerprint($this->resolveVector($namespaces)['generations']);
    }

    public function authorityClock(): int
    {
        return $this->readGenerationValues([NamespacePath::AUTHORITY_CLOCK])[NamespacePath::AUTHORITY_CLOCK];
    }

    /** Ensure the reserved authority row exists with a positive initial value. */
    public function ensureAuthorityClock(): int
    {
        $stored = $this->readStoredRows([NamespacePath::AUTHORITY_CLOCK]);
        $current = (int)($stored[NamespacePath::AUTHORITY_CLOCK] ?? 0);
        if ($current > 0) {
            return $current;
        }

        $this->newGenerationModel()
            ->insert(
                [
                    NamespaceVersion::schema_fields_HASH => self::hashNamespace(NamespacePath::AUTHORITY_CLOCK),
                    NamespaceVersion::schema_fields_NAMESPACE => NamespacePath::AUTHORITY_CLOCK,
                    NamespaceVersion::schema_fields_GENERATION => 1,
                    NamespaceVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ],
                [NamespaceVersion::schema_fields_HASH],
                NamespaceVersion::schema_fields_UPDATED_AT,
            )
            ->fetch();

        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $stored = $this->readStoredRows([NamespacePath::AUTHORITY_CLOCK]);
            $current = (int)($stored[NamespacePath::AUTHORITY_CLOCK] ?? 0);
            if ($current > 0) {
                return $current;
            }
            $this->newGenerationModel()
                ->where(NamespaceVersion::schema_fields_HASH, self::hashNamespace(NamespacePath::AUTHORITY_CLOCK))
                ->where(NamespaceVersion::schema_fields_NAMESPACE, NamespacePath::AUTHORITY_CLOCK)
                ->where(NamespaceVersion::schema_fields_GENERATION, 0)
                ->update([
                    NamespaceVersion::schema_fields_GENERATION => 1,
                    NamespaceVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->fetch();
        }

        throw new \RuntimeException(__('无法初始化缓存命名空间权威时钟'));
    }

    /**
     * Reconcile every process-known namespace and @clock from the database.
     *
     * @return array{authority_clock:int,generations:array<string,int>}
     */
    public function reconcileProcessSnapshot(): array
    {
        $this->ensureAuthorityClock();
        $known = $this->snapshot->knownNamespaces();
        $requested = \array_values(\array_unique(\array_merge(
            [NamespacePath::AUTHORITY_CLOCK],
            $known,
        )));
        $stored = $this->readGenerationValues($requested);
        $authorityClock = \max(1, (int)$stored[NamespacePath::AUTHORITY_CLOCK]);
        $generations = [];
        foreach ($known as $namespace) {
            $generations[$namespace] = \max(0, (int)($stored[$namespace] ?? 0));
        }
        \ksort($generations, \SORT_STRING);
        $this->snapshot->replaceProcessSnapshot($authorityClock, $generations);

        return $this->snapshot->processSnapshot();
    }

    /**
     * Apply an authoritative IPC delta without clearing any cache pool.
     *
     * @param array<string,int> $changes
     * @return array{authority_clock:int,generations:array<string,int>}
     */
    public function applyAuthorityChanges(int $authorityClock, array $changes): array
    {
        if ($authorityClock <= 0) {
            throw new \InvalidArgumentException(__('命名空间权威时钟必须为正数'));
        }
        $normalized = [];
        foreach ($changes as $namespace => $generation) {
            if (!\is_string($namespace) || !\is_int($generation) || $generation <= 0) {
                throw new \InvalidArgumentException(__('命名空间 IPC 代际包含无效成员'));
            }
            $canonical = $this->path->canonicalize($namespace);
            $normalized[$canonical] = \max((int)($normalized[$canonical] ?? 0), $generation);
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException(__('命名空间 IPC 代际不能为空'));
        }
        \ksort($normalized, \SORT_STRING);
        $this->snapshot->advance($authorityClock, $normalized);

        return $this->snapshot->processSnapshot();
    }

    public function beginRequestSnapshot(): void
    {
        $this->snapshot->beginRequest();
    }

    public function endRequestSnapshot(): void
    {
        $this->snapshot->endRequest();
    }

    /** @return array{authority_clock:int,generations:array<string,int>} */
    public function processSnapshot(): array
    {
        return $this->snapshot->processSnapshot();
    }

    /**
     * Atomically bump each exact namespace once per owner transaction and then
     * advance @clock. A parent bump invalidates descendants because readers
     * always include every ancestor in their vector.
     *
     * @param list<string> $namespaces
     * @return array{authority_clock:int,changes:array<string,int>}
     */
    public function bumpMany(array $namespaces): array
    {
        $canonical = $this->path->canonicalizeMany($namespaces);
        $connection = $this->generationModel->getConnection();
        $transactions = $this->transactionCoordinator();

        return $transactions->run(
            $connection,
            function () use ($canonical, $connection, $transactions): array {
                $connectionKey = self::connectionKey($connection);
                $state = self::transactionState($connectionKey);
                $this->registerTransactionCallbacks($connection, $connectionKey, $state, $transactions);
                $state = self::transactionState($connectionKey);

                $pending = array_values(array_diff($canonical, array_keys($state['changes'])));
                if ($pending !== []) {
                    $readTargets = $pending;
                    if ($state['authority_clock'] === null) {
                        $readTargets[] = NamespacePath::AUTHORITY_CLOCK;
                    }
                    $stored = $this->readStoredRows($readTargets);

                    foreach ($pending as $namespace) {
                        $state['changes'][$namespace] = $this->bumpStoredValue(
                            $namespace,
                            $stored[$namespace] ?? null
                        );
                    }
                    if ($state['authority_clock'] === null) {
                        $state['authority_clock'] = $this->bumpStoredValue(
                            NamespacePath::AUTHORITY_CLOCK,
                            $stored[NamespacePath::AUTHORITY_CLOCK] ?? null
                        );
                    }
                    ksort($state['changes'], SORT_STRING);
                    self::storeTransactionState($connectionKey, $state);
                }

                $changes = [];
                foreach ($canonical as $namespace) {
                    if (isset($state['changes'][$namespace])) {
                        $changes[$namespace] = $state['changes'][$namespace];
                    }
                }
                ksort($changes, SORT_STRING);
                return [
                    'authority_clock' => max(0, (int)($state['authority_clock'] ?? 0)),
                    'changes' => $changes,
                ];
            }
        );
    }

    /** @return array{authority_clock:int,changes:array<string,int>} */
    public function bump(string $namespace): array
    {
        return $this->bumpMany([$namespace]);
    }

    public function clearSnapshot(): void
    {
        $this->snapshot->clear();
    }

    /**
     * @param list<string> $requested public canonical namespaces plus optional @clock
     * @return array<string,int>
     */
    private function readGenerationValues(array $requested): array
    {
        $stored = $this->readStoredRows($requested);
        $values = [];
        foreach ($requested as $namespace) {
            $values[$namespace] = max(0, (int)($stored[$namespace] ?? 0));
        }
        return $values;
    }

    /**
     * @param list<string> $requested public canonical namespaces plus optional @clock
     * @return array<string,int> only rows that exist
     */
    private function readStoredRows(array $requested): array
    {
        if ($requested === []) {
            return [];
        }

        $expectedByHash = [];
        foreach (array_values(array_unique($requested)) as $namespace) {
            if (!is_string($namespace) || $namespace === '') {
                throw new \InvalidArgumentException(__('命名空间代际查询包含无效名称'));
            }
            if ($namespace !== NamespacePath::AUTHORITY_CLOCK) {
                $this->path->canonicalize($namespace);
            }
            $hash = self::hashNamespace($namespace);
            if (isset($expectedByHash[$hash]) && $expectedByHash[$hash] !== $namespace) {
                throw new \RuntimeException(__('缓存命名空间发生 SHA-256 碰撞'));
            }
            $expectedByHash[$hash] = $namespace;
        }

        $rows = $this->newGenerationModel()
            ->fields(implode(',', [
                NamespaceVersion::schema_fields_HASH,
                NamespaceVersion::schema_fields_NAMESPACE,
                NamespaceVersion::schema_fields_GENERATION,
            ]))
            ->where(NamespaceVersion::schema_fields_HASH, array_keys($expectedByHash), 'IN')
            ->select()
            ->fetchArray();

        $stored = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hash = (string)($row[NamespaceVersion::schema_fields_HASH] ?? '');
            $namespace = (string)($row[NamespaceVersion::schema_fields_NAMESPACE] ?? '');
            $expected = $expectedByHash[$hash] ?? null;
            if ($expected === null || !hash_equals($hash, self::hashNamespace($namespace)) || $expected !== $namespace) {
                throw new \RuntimeException(__('缓存命名空间哈希与完整名称不一致'));
            }
            $generation = (int)($row[NamespaceVersion::schema_fields_GENERATION] ?? -1);
            if ($generation < 0) {
                throw new \RuntimeException(__('缓存命名空间代际不能为负数'));
            }
            $stored[$namespace] = $generation;
        }
        return $stored;
    }

    private function bumpStoredValue(string $namespace, ?int $expected): int
    {
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            if ($expected === null) {
                // Cross-database conflict-safe seed: insert generation 0, or
                // update only the timestamp when another writer seeded first.
                // Every caller still performs its own guarded +1 CAS below.
                $this->newGenerationModel()
                    ->insert(
                        [
                            NamespaceVersion::schema_fields_HASH => self::hashNamespace($namespace),
                            NamespaceVersion::schema_fields_NAMESPACE => $namespace,
                            NamespaceVersion::schema_fields_GENERATION => 0,
                            NamespaceVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                        ],
                        [NamespaceVersion::schema_fields_HASH],
                        NamespaceVersion::schema_fields_UPDATED_AT
                    )
                    ->fetch();

                $seeded = $this->readStoredRows([$namespace]);
                if (isset($seeded[$namespace])) {
                    $expected = $seeded[$namespace];
                } else {
                    continue;
                }
            }

            if ($expected >= PHP_INT_MAX) {
                throw new \OverflowException(__('缓存命名空间代际已达到 64 位整数上限'));
            }
            $next = $expected + 1;
            $update = $this->newGenerationModel();
            $update->where(NamespaceVersion::schema_fields_HASH, self::hashNamespace($namespace))
                ->where(NamespaceVersion::schema_fields_NAMESPACE, $namespace)
                ->where(NamespaceVersion::schema_fields_GENERATION, $expected);
            $updated = $update->getQuery()
                ->update([
                    NamespaceVersion::schema_fields_GENERATION => $next,
                    NamespaceVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->fetch();
            if ($this->casUpdatedOneRow($updated)) {
                return $next;
            }

            // Generations never decrease and rows are never deleted. Advancing
            // the expected value avoids a stale repeatable-read snapshot after
            // another transaction wins the CAS (notably MySQL/InnoDB).
            $expected = $next;
        }

        throw new \RuntimeException(
            __('缓存命名空间 %{1} 在 %{2} 次 CAS 后仍有并发冲突', [$namespace, self::MAX_CAS_ATTEMPTS])
        );
    }

    private function casUpdatedOneRow(mixed $result): bool
    {
        if ($result === true || (is_int($result) && $result === 1)) {
            return true;
        }
        if (is_int($result) && $result > 1) {
            throw new \RuntimeException(__('缓存命名空间 CAS 意外更新了多行'));
        }

        $connection = $this->generationModel->getConnection();
        $connector = $connection->getConnector();
        $databaseType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if ($databaseType !== 'sqlite') {
            return false;
        }

        // SQLite's query adapter currently turns an UPDATE result set into
        // `(bool) []`, so a successful guarded write is reported as false.
        // `changes()` is connection-local and must be read immediately after
        // the CAS statement, before any other statement runs on this connector.
        $rows = $connector->query('SELECT changes() AS affected_rows')->fetch();
        $affected = is_array($rows)
            && isset($rows[0])
            && is_array($rows[0])
            && array_key_exists('affected_rows', $rows[0])
            ? $rows[0]['affected_rows']
            : null;
        if (!(is_int($affected)
            || (is_string($affected) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $affected) === 1))) {
            throw new \RuntimeException(__('无法确认 SQLite 缓存命名空间 CAS 影响行数'));
        }
        $affected = (int)$affected;
        if ($affected > 1) {
            throw new \RuntimeException(__('缓存命名空间 CAS 意外更新了多行'));
        }

        return $affected === 1;
    }

    /**
     * @param array{authority_clock:?int,changes:array<string,int>,callbacks_registered:bool} $state
     */
    private function registerTransactionCallbacks(
        ConnectionFactory $connection,
        string $connectionKey,
        array $state,
        TransactionCoordinatorInterface $transactions,
    ): void {
        if ($state['callbacks_registered']) {
            return;
        }
        $state['callbacks_registered'] = true;
        self::storeTransactionState($connectionKey, $state);

        $transactions->afterRollback(
            $connection,
            'cache_namespace_generation_state',
            static function () use ($connectionKey): void {
                self::forgetTransactionState($connectionKey);
            }
        );
        $snapshot = $this->snapshot;
        $transactions->afterCommit(
            $connection,
            'cache_namespace_generation_snapshot',
            static function () use ($connectionKey, $snapshot): void {
                $committed = self::transactionState($connectionKey);
                if ($committed['authority_clock'] !== null && $committed['changes'] !== []) {
                    $snapshot->advance($committed['authority_clock'], $committed['changes']);
                }
                self::forgetTransactionState($connectionKey);
            }
        );
    }

    private function newGenerationModel(): NamespaceVersion
    {
        $model = clone $this->generationModel;
        return $model->clearData()->clearQuery();
    }

    private function transactionCoordinator(): TransactionCoordinatorInterface
    {
        if ($this->transactions === null) {
            $this->transactions = ObjectManager::getInstance(TransactionCoordinator::class);
        }
        return $this->transactions;
    }

    private static function hashNamespace(string $namespace): string
    {
        return hash('sha256', $namespace);
    }

    private static function connectionKey(ConnectionFactory $connection): string
    {
        return TransactionContext::logicalConnectionKey($connection->getConnector());
    }

    /** @return array{authority_clock:?int,changes:array<string,int>,callbacks_registered:bool} */
    private static function transactionState(string $connectionKey): array
    {
        $all = RequestContext::get(self::TRANSACTION_STATE_KEY, []);
        $state = is_array($all) ? ($all[$connectionKey] ?? []) : [];
        $changes = is_array($state['changes'] ?? null) ? $state['changes'] : [];
        return [
            'authority_clock' => isset($state['authority_clock']) && is_int($state['authority_clock'])
                ? $state['authority_clock']
                : null,
            'changes' => $changes,
            'callbacks_registered' => (bool)($state['callbacks_registered'] ?? false),
        ];
    }

    /** @param array{authority_clock:?int,changes:array<string,int>,callbacks_registered:bool} $state */
    private static function storeTransactionState(string $connectionKey, array $state): void
    {
        $all = RequestContext::get(self::TRANSACTION_STATE_KEY, []);
        $all = is_array($all) ? $all : [];
        $all[$connectionKey] = $state;
        RequestContext::set(self::TRANSACTION_STATE_KEY, $all);
    }

    private static function forgetTransactionState(string $connectionKey): void
    {
        $all = RequestContext::get(self::TRANSACTION_STATE_KEY, []);
        if (!is_array($all)) {
            return;
        }
        unset($all[$connectionKey]);
        if ($all === []) {
            RequestContext::remove(self::TRANSACTION_STATE_KEY);
            return;
        }
        RequestContext::set(self::TRANSACTION_STATE_KEY, $all);
    }
}
