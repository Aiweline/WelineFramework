<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * MIG-P2-PAYMENT 的隔离 clone 数据库探针与唯一事务写入器。
 *
 * 表名和列名均来自静态清单；调用方必须先通过 migration registry
 * fingerprint guard。本类不调用 Provider，也不写 Payment business outbox。
 */
final class PaymentCompatibilityDatabaseProbe
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function inspect(array $config): array
    {
        $pdo = $this->connect($config);
        $tables = $this->tables($config);
        $presence = [];
        foreach ($tables as $logical => $physical) {
            $presence[$logical] = $this->tableExists($pdo, $physical);
        }
        $missing = array_keys(array_filter(
            $presence,
            static fn (bool $exists): bool => !$exists,
        ));
        if ($missing !== []) {
            return [
                'ok' => false,
                'error' => 'mig_p2_payment_required_tables_missing',
                'missing_tables' => $missing,
                'table_presence' => $presence,
                'physical_tables' => $tables,
            ];
        }

        $snapshots = [
            'transaction' => $this->snapshot($pdo, $tables['transaction'], 'transaction_id', [
                'transaction_id', 'transaction_no', 'order_id', 'method_code', 'amount',
                'currency', 'status', 'scope', 'request_data', 'response_data',
                'callback_data', 'created_at', 'updated_at', 'paid_at',
            ]),
            'intent' => $this->snapshot($pdo, $tables['intent'], 'intent_id', [
                'intent_id', 'intent_code', 'environment', 'payable_type', 'payable_id',
                'method_code', 'provider_code', 'merchant_account', 'scope',
                'amount_minor', 'currency_code', 'precision', 'status', 'active_flag',
                'active_guard', 'request_hash', 'idempotency_key',
            ]),
            'attempt' => $this->snapshot($pdo, $tables['attempt'], 'attempt_id', [
                'attempt_id', 'attempt_code', 'intent_code', 'environment', 'payable_type',
                'payable_id', 'method_code', 'provider_code', 'merchant_account', 'scope',
                'payment_currency_code', 'amount_minor', 'precision', 'status',
                'nonterminal_guard', 'version', 'idempotency_key', 'provider_reference',
                'provider_reference_guard',
            ]),
            'refund' => $this->snapshot($pdo, $tables['refund'], 'refund_id', [
                'refund_id', 'refund_code', 'transaction_code', 'intent_code',
                'attempt_code', 'requested_amount_minor', 'approved_amount_minor',
                'currency', 'precision', 'status', 'channel_status',
            ]),
            'inbox' => $this->snapshot($pdo, $tables['inbox'], 'inbox_id', [
                'inbox_id', 'inbox_code', 'endpoint_code', 'provider_event_id',
                'provider_code', 'merchant_account', 'environment', 'status',
                'intent_code', 'attempt_code',
            ]),
            'outbox' => $this->snapshot($pdo, $tables['outbox'], 'outbox_id', [
                'outbox_id', 'outbox_code', 'effect_key', 'inbox_code', 'intent_code',
                'attempt_code', 'effect_type', 'status',
            ]),
            'ledger' => $this->snapshot($pdo, $tables['ledger'], 'ledger_id', [
                'ledger_id', 'ledger_code', 'ledger_type', 'direction', 'debit_minor',
                'credit_minor', 'currency', 'precision', 'transaction_code',
                'refund_code', 'intent_code', 'attempt_code',
            ]),
            'reconciliation' => $this->snapshot(
                $pdo,
                $tables['reconciliation'],
                'audit_id',
                [
                    'audit_id', 'audit_code', 'mode', 'scope', 'diff_count',
                    'repaired_count', 'status', 'idempotency_key',
                ],
            ),
        ];

        $transactions = $this->fetchAll(
            $pdo,
            'SELECT transaction_id, transaction_no, order_id, method_code, amount, '
            . 'currency, status, scope, request_data, response_data, callback_data, '
            . 'created_at, updated_at, paid_at FROM ' . $tables['transaction']
            . ' ORDER BY transaction_id ASC',
        );
        $compatIntents = $this->fetchKeyed(
            $pdo,
            'SELECT intent_code, environment, payable_type, payable_id, method_code, '
            . 'provider_code, merchant_account, scope, amount_minor, currency_code, '
            . 'precision, status, active_flag, active_guard, request_hash, idempotency_key '
            . 'FROM ' . $tables['intent'] . " WHERE intent_code LIKE 'compat_intent_%'",
            'intent_code',
        );
        $compatAttempts = $this->fetchKeyed(
            $pdo,
            'SELECT attempt_code, intent_code, environment, payable_type, payable_id, '
            . 'method_code, provider_code, merchant_account, scope, payment_currency_code, '
            . 'amount_minor, precision, status, nonterminal_guard, version, idempotency_key, '
            . 'provider_reference, provider_reference_guard FROM ' . $tables['attempt']
            . " WHERE attempt_code LIKE 'compat_attempt_%'",
            'attempt_code',
        );
        $providerOwners = $this->fetchAll(
            $pdo,
            'SELECT attempt_code, merchant_account, environment, provider_reference '
            . 'FROM ' . $tables['attempt']
            . ' WHERE provider_reference IS NOT NULL AND provider_reference <> \'\' '
            . 'ORDER BY attempt_id ASC',
        );

        return [
            'ok' => true,
            'error' => null,
            'table_presence' => $presence,
            'physical_tables' => $tables,
            'schema_fingerprints' => $this->schemaFingerprints($pdo, $tables),
            'snapshots' => $snapshots,
            'transactions' => $transactions,
            'compat_intents' => $compatIntents,
            'compat_attempts' => $compatAttempts,
            'provider_reference_owners' => $providerOwners,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array{intent:array<string,mixed>,attempt:array<string,mixed>}> $plans
     * @return array{mapped:int,already:int,intent_count:int,attempt_count:int}
     */
    public function applyMappings(array $config, array $plans): array
    {
        $pdo = $this->connect($config);
        $tables = $this->tables($config);
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'LOCK TABLE ' . $tables['intent'] . ', ' . $tables['attempt']
                . ' IN SHARE ROW EXCLUSIVE MODE',
            );
            $mapped = 0;
            $already = 0;
            foreach ($plans as $plan) {
                $intent = $plan['intent'];
                $attempt = $plan['attempt'];
                $intentCode = (string) $intent['intent_code'];
                $attemptCode = (string) $attempt['attempt_code'];
                $existingIntent = $this->fetchOne(
                    $pdo,
                    'SELECT intent_code, request_hash FROM ' . $tables['intent']
                    . ' WHERE intent_code = :code',
                    ['code' => $intentCode],
                );
                $existingAttempt = $this->fetchOne(
                    $pdo,
                    'SELECT attempt_code, intent_code, idempotency_key FROM ' . $tables['attempt']
                    . ' WHERE attempt_code = :code',
                    ['code' => $attemptCode],
                );
                if ($existingIntent !== null || $existingAttempt !== null) {
                    if ($existingIntent === null || $existingAttempt === null
                        || !hash_equals((string) $intent['request_hash'], (string) $existingIntent['request_hash'])
                        || !hash_equals($intentCode, (string) $existingAttempt['intent_code'])
                        || !hash_equals((string) $attempt['idempotency_key'], (string) $existingAttempt['idempotency_key'])
                    ) {
                        throw new \RuntimeException('mig_p2_payment_reader_conflict:' . $intentCode);
                    }
                    $already++;
                    continue;
                }

                $this->insert($pdo, $tables['intent'], [
                    'intent_code' => $intentCode,
                    'environment' => $intent['environment'],
                    'payable_type' => $intent['payable_type'],
                    'payable_id' => $intent['payable_id'],
                    'method_code' => $intent['method_code'],
                    'provider_code' => $intent['provider_code'],
                    'merchant_account' => $intent['merchant_account'],
                    'scope' => $intent['scope'],
                    'amount_minor' => $intent['amount_minor'],
                    'currency_code' => $intent['currency_code'],
                    'precision' => $intent['precision'],
                    'status' => $intent['status'],
                    'active_flag' => $intent['active_flag'],
                    'active_guard' => $intent['active_guard'],
                    'request_hash' => $intent['request_hash'],
                    'idempotency_key' => $intent['idempotency_key'],
                    'amount_snapshot' => $intent['amount_snapshot'],
                    'config_snapshot' => $intent['config_snapshot'],
                    'terms_snapshot' => $intent['terms_snapshot'],
                    'created_at' => $intent['created_at'],
                    'updated_at' => $intent['updated_at'],
                ]);
                $this->insert($pdo, $tables['attempt'], [
                    'attempt_code' => $attemptCode,
                    'intent_code' => $attempt['intent_code'],
                    'environment' => $attempt['environment'],
                    'payable_type' => $attempt['payable_type'],
                    'payable_id' => $attempt['payable_id'],
                    'method_code' => $attempt['method_code'],
                    'provider_code' => $attempt['provider_code'],
                    'merchant_account' => $attempt['merchant_account'],
                    'scope' => $attempt['scope'],
                    'payment_currency_code' => $attempt['payment_currency_code'],
                    'amount_minor' => $attempt['amount_minor'],
                    'precision' => $attempt['precision'],
                    'status' => $attempt['status'],
                    'nonterminal_guard' => $attempt['nonterminal_guard'],
                    'version' => $attempt['version'],
                    'cas_token' => $attempt['cas_token'],
                    'idempotency_key' => $attempt['idempotency_key'],
                    'provider_reference' => $attempt['provider_reference'],
                    'provider_reference_guard' => $attempt['provider_reference_guard'],
                    'provider_request_key' => $attempt['provider_request_key'],
                    'request_snapshot' => $attempt['request_snapshot'],
                    'response_snapshot' => $attempt['response_snapshot'],
                    'created_at' => $attempt['created_at'],
                    'closed_at' => $attempt['closed_at'],
                ]);
                $mapped++;
            }
            $pdo->commit();

            return [
                'mapped' => $mapped,
                'already' => $already,
                'intent_count' => $this->countRows($pdo, $tables['intent']),
                'attempt_count' => $this->countRows($pdo, $tables['attempt']),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function tables(array $config): array
    {
        $prefix = (string) ($config['prefix'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new \RuntimeException('mig_p2_payment_probe_invalid_table_prefix');
        }

        return [
            'transaction' => $prefix . 'weline_payment_transaction',
            'intent' => $prefix . 'weline_payment_intent',
            'attempt' => $prefix . 'weline_payment_attempt',
            'refund' => $prefix . 'weline_payment_refund',
            'inbox' => $prefix . 'weline_payment_webhook_inbox',
            'outbox' => $prefix . 'weline_payment_outbox',
            'ledger' => $prefix . 'weline_payment_ledger',
            'reconciliation' => $prefix . 'weline_payment_reconciliation_audit',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(array $config): \PDO
    {
        $type = strtolower(trim((string) ($config['type'] ?? 'pgsql')));
        if ($type !== 'pgsql') {
            throw new \RuntimeException('mig_p2_payment_probe_requires_pgsql:' . $type);
        }
        $dsn = 'pgsql:host=' . (string) ($config['hostname'] ?? '127.0.0.1')
            . ';port=' . (string) ($config['hostport'] ?? '5432')
            . ';dbname=' . (string) ($config['database'] ?? '');

        return new \PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT => false,
            ],
        );
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT to_regclass(:table_name)');
        $statement->execute(['table_name' => 'public.' . $table]);

        return $statement->fetchColumn() !== null;
    }

    /**
     * @param array<string, string> $tables
     * @return array<string, string>
     */
    private function schemaFingerprints(\PDO $pdo, array $tables): array
    {
        $out = [];
        $statement = $pdo->prepare(
            'SELECT column_name, data_type, is_nullable, column_default '
            . 'FROM information_schema.columns '
            . "WHERE table_schema = 'public' AND table_name = :table_name "
            . 'ORDER BY ordinal_position ASC',
        );
        foreach ($tables as $logical => $physical) {
            $statement->execute(['table_name' => $physical]);
            $out[$logical] = $this->rowsDigest($statement->fetchAll());
        }

        return $out;
    }

    /**
     * @param list<string> $columns
     * @return array{count:int,watermark:int,digest:string}
     */
    private function snapshot(\PDO $pdo, string $table, string $primaryKey, array $columns): array
    {
        $statement = $pdo->query(
            'SELECT ' . implode(', ', $columns) . ' FROM ' . $table
            . ' ORDER BY ' . $primaryKey . ' ASC',
        );
        if ($statement === false) {
            throw new \RuntimeException('mig_p2_payment_probe_query_failed:' . $table);
        }
        $context = hash_init('sha256');
        $count = 0;
        $watermark = 0;
        while (($row = $statement->fetch()) !== false) {
            $count++;
            $watermark = max($watermark, (int) ($row[$primaryKey] ?? 0));
            hash_update(
                $context,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
        }

        return ['count' => $count, 'watermark' => $watermark, 'digest' => hash_final($context)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(\PDO $pdo, string $table, array $row): void
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $pdo->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')',
        );
        $statement->execute($row);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(\PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(\PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('mig_p2_payment_probe_query_failed');
        }
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchKeyed(\PDO $pdo, string $sql, string $key): array
    {
        $out = [];
        foreach ($this->fetchAll($pdo, $sql) as $row) {
            $value = (string) ($row[$key] ?? '');
            if ($value !== '') {
                $out[$value] = $row;
            }
        }

        return $out;
    }

    private function countRows(\PDO $pdo, string $table): int
    {
        $value = $pdo->query('SELECT COUNT(*) FROM ' . $table)?->fetchColumn();

        return (int) $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rowsDigest(array $rows): string
    {
        return hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
