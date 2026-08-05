<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

/**
 * Registered PostgreSQL clone probe/writer for MIG-P3A.
 *
 * All identifiers come from a static table list plus a validated framework
 * prefix. The caller must pass the migration-registry fingerprint guard before
 * invoking any mutating method.
 */
final class WarehouseMigrationDatabaseProbe
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function inspect(array $config): array
    {
        return $this->inspectWithPdo($this->connect($config), $config);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $quotaPlans
     * @param list<array<string, mixed>> $reservationPlans
     * @param list<array<string, mixed>> $ledgerPlans
     * @param array<string, mixed> $expectedSnapshot
     * @return array<string, int>
     */
    public function applyMappings(
        array $config,
        array $quotaPlans,
        array $reservationPlans,
        array $ledgerPlans,
        array $expectedSnapshot,
    ): array {
        $pdo = $this->connect($config);
        $tables = $this->tables($config);
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'LOCK TABLE '
                . implode(', ', [
                    $tables['stock'],
                    $tables['reservation'],
                    $tables['ledger'],
                    $tables['warehouse'],
                    $tables['authorization'],
                    $tables['quota'],
                ])
                . ' IN SHARE ROW EXCLUSIVE MODE',
            );
            $locked = $this->inspectWithPdo($pdo, $config);
            $this->assertExpectedSnapshot($expectedSnapshot, $locked);

            $quotaMapped = 0;
            $quotaAlready = 0;
            foreach ($quotaPlans as $plan) {
                $existing = $this->fetchOne(
                    $pdo,
                    'SELECT quota_id, pool_id, qty_minor, quota_version FROM '
                    . $tables['quota']
                    . ' WHERE website_id=:website_id AND warehouse_id=:warehouse_id'
                    . ' AND offer_id=:offer_id',
                    [
                        'website_id' => (int) $plan['website_id'],
                        'warehouse_id' => (int) $plan['warehouse_id'],
                        'offer_id' => (int) $plan['offer_id'],
                    ],
                );
                if ($existing !== null) {
                    if ((int) $existing['qty_minor'] !== (int) $plan['qty_minor']) {
                        throw new \RuntimeException(
                            'mig_p3a_warehouse_quota_conflict:' . (string) $plan['key'],
                        );
                    }
                    $quotaAlready++;
                    continue;
                }
                $statement = $pdo->prepare(
                    'INSERT INTO ' . $tables['quota']
                    . ' (website_id,warehouse_id,pool_id,offer_id,qty_minor,quota_version,created_at,updated_at)'
                    . ' VALUES (:website_id,:warehouse_id,NULL,:offer_id,:qty_minor,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                );
                $statement->execute([
                    'website_id' => (int) $plan['website_id'],
                    'warehouse_id' => (int) $plan['warehouse_id'],
                    'offer_id' => (int) $plan['offer_id'],
                    'qty_minor' => (int) $plan['qty_minor'],
                ]);
                $quotaMapped++;
            }

            $reservationMapped = 0;
            $reservationAlready = 0;
            foreach ($reservationPlans as $plan) {
                $statement = $pdo->prepare(
                    'UPDATE ' . $tables['reservation']
                    . ' SET warehouse_id=:warehouse_id, updated_at=CURRENT_TIMESTAMP'
                    . ' WHERE reservation_id=:reservation_id AND warehouse_id IS NULL',
                );
                $statement->execute([
                    'warehouse_id' => (int) $plan['warehouse_id'],
                    'reservation_id' => (int) $plan['reservation_id'],
                ]);
                if ($statement->rowCount() === 1) {
                    $reservationMapped++;
                    continue;
                }
                $existing = $this->fetchOne(
                    $pdo,
                    'SELECT warehouse_id FROM ' . $tables['reservation']
                    . ' WHERE reservation_id=:reservation_id',
                    ['reservation_id' => (int) $plan['reservation_id']],
                );
                if ($existing === null
                    || (int) ($existing['warehouse_id'] ?? 0) !== (int) $plan['warehouse_id']
                ) {
                    throw new \RuntimeException(
                        'mig_p3a_warehouse_reservation_conflict:'
                        . (string) $plan['reservation_uuid'],
                    );
                }
                $reservationAlready++;
            }

            $ledgerMapped = 0;
            $ledgerAlready = 0;
            foreach ($ledgerPlans as $plan) {
                $statement = $pdo->prepare(
                    'UPDATE ' . $tables['ledger']
                    . ' SET warehouse_id=:warehouse_id'
                    . ' WHERE ledger_id=:ledger_id AND warehouse_id IS NULL',
                );
                $statement->execute([
                    'warehouse_id' => (int) $plan['warehouse_id'],
                    'ledger_id' => (int) $plan['ledger_id'],
                ]);
                if ($statement->rowCount() === 1) {
                    $ledgerMapped++;
                    continue;
                }
                $existing = $this->fetchOne(
                    $pdo,
                    'SELECT warehouse_id FROM ' . $tables['ledger']
                    . ' WHERE ledger_id=:ledger_id',
                    ['ledger_id' => (int) $plan['ledger_id']],
                );
                if ($existing === null
                    || (int) ($existing['warehouse_id'] ?? 0) !== (int) $plan['warehouse_id']
                ) {
                    throw new \RuntimeException(
                        'mig_p3a_warehouse_ledger_conflict:' . (string) $plan['event_uuid'],
                    );
                }
                $ledgerAlready++;
            }

            $pdo->commit();

            return [
                'quota_mapped' => $quotaMapped,
                'quota_already' => $quotaAlready,
                'reservation_mapped' => $reservationMapped,
                'reservation_already' => $reservationAlready,
                'ledger_mapped' => $ledgerMapped,
                'ledger_already' => $ledgerAlready,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int> $websiteIds
     * @return array{updated:int,enabled_count:int}
     */
    public function setWriterEnabled(array $config, array $websiteIds, bool $enabled): array
    {
        $pdo = $this->connect($config);
        $tables = $this->tables($config);
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'LOCK TABLE ' . $tables['authorization'] . ' IN SHARE ROW EXCLUSIVE MODE',
            );
            $params = [];
            $where = 'is_default=1 AND enabled=1';
            if ($websiteIds !== []) {
                $marks = [];
                foreach (array_values(array_unique(array_map('intval', $websiteIds))) as $index => $websiteId) {
                    if ($websiteId < 0) {
                        throw new \InvalidArgumentException(
                            'mig_p3a_warehouse_allowlist_website_invalid',
                        );
                    }
                    $name = 'website_' . $index;
                    $marks[] = ':' . $name;
                    $params[$name] = $websiteId;
                }
                $where .= ' AND website_id IN (' . implode(',', $marks) . ')';
            } elseif ($enabled) {
                throw new \InvalidArgumentException(
                    'mig_p3a_warehouse_allowlist_required',
                );
            } else {
                $where = 'writer_enabled=1';
            }

            if ($enabled) {
                $count = $pdo->prepare(
                    'SELECT COUNT(*) FROM ' . $tables['authorization'] . ' WHERE ' . $where,
                );
                $count->execute($params);
                if ((int) $count->fetchColumn() === 0) {
                    throw new \RuntimeException(
                        'mig_p3a_warehouse_allowlist_has_no_default_binding',
                    );
                }
            }

            $statement = $pdo->prepare(
                'UPDATE ' . $tables['authorization']
                . ' SET writer_enabled=:writer_set,'
                . ' authorization_version=authorization_version+1,'
                . ' updated_at=CURRENT_TIMESTAMP WHERE ' . $where
                . ' AND writer_enabled<>:writer_compare',
            );
            $writerValue = $enabled ? 1 : 0;
            $statement->execute([
                'writer_set' => $writerValue,
                'writer_compare' => $writerValue,
            ] + $params);
            $updated = $statement->rowCount();
            $enabledCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $tables['authorization']
                . ' WHERE writer_enabled=1',
            )->fetchColumn();
            $pdo->commit();

            return ['updated' => $updated, 'enabled_count' => $enabledCount];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertExpectedSnapshot(array $expected, array $actual): void
    {
        foreach ([
            'stock',
            'reservation_immutable',
            'ledger_immutable',
            'warehouse',
            'authorization_mapping',
            'authorization_state',
            'quota',
        ] as $name) {
            $expectedRow = (array) ($expected['snapshots'][$name] ?? []);
            $actualRow = (array) ($actual['snapshots'][$name] ?? []);
            if ((int) ($expectedRow['count'] ?? -1) !== (int) ($actualRow['count'] ?? -2)
                || !hash_equals(
                    (string) ($expectedRow['digest'] ?? ''),
                    (string) ($actualRow['digest'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'mig_p3a_warehouse_source_changed_under_lock:' . $name,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function inspectWithPdo(\PDO $pdo, array $config): array
    {
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
                'error' => 'mig_p3a_warehouse_required_tables_missing',
                'missing_tables' => $missing,
                'table_presence' => $presence,
                'physical_tables' => $tables,
            ];
        }

        $stocks = $this->fetchAll(
            $pdo,
            'SELECT stock_id,website_id,store_id,offer_id,strategy,on_hand_minor,'
            . 'reserved_minor,oversell_allowance,preorder_allowance,stock_version'
            . ' FROM ' . $tables['stock'] . ' ORDER BY stock_id ASC',
        );
        $reservations = $this->fetchAll(
            $pdo,
            'SELECT reservation_id,reservation_uuid,website_id,store_id,offer_id,'
            . 'quantity_minor,state,idempotency_key,request_hash,warehouse_id,'
            . 'lease_owner_attempt_code,lease_started_at,queued_order,lease_version,'
            . 'lease_expires_at,lease_max_expires_at,created_at'
            . ' FROM ' . $tables['reservation'] . ' ORDER BY reservation_id ASC',
        );
        $ledgers = $this->fetchAll(
            $pdo,
            'SELECT ledger_id,event_uuid,event_type,website_id,store_id,offer_id,'
            . 'warehouse_id,qty_delta_minor,strategy,oversell_allowance,'
            . 'preorder_allowance,reservation_uuid,idempotency_key,request_hash,created_at'
            . ' FROM ' . $tables['ledger'] . ' ORDER BY ledger_id ASC',
        );
        $warehouses = $this->fetchAll(
            $pdo,
            'SELECT warehouse_id,website_id,warehouse_code,name,mode,warehouse_type,'
            . 'is_default_logical,default_logical_guard,enabled'
            . ' FROM ' . $tables['warehouse'] . ' ORDER BY warehouse_id ASC',
        );
        $authorizations = $this->fetchAll(
            $pdo,
            'SELECT authorization_id,website_id,store_id,warehouse_id,'
            . 'store_mode_snapshot,is_default,default_guard,enabled,writer_enabled,'
            . 'authorization_version FROM ' . $tables['authorization']
            . ' ORDER BY authorization_id ASC',
        );
        $quotas = $this->fetchAll(
            $pdo,
            'SELECT quota_id,website_id,warehouse_id,pool_id,offer_id,qty_minor,'
            . 'quota_version FROM ' . $tables['quota'] . ' ORDER BY quota_id ASC',
        );

        $reservationImmutable = array_map(
            static function (array $row): array {
                unset($row['warehouse_id']);
                return $row;
            },
            $reservations,
        );
        $ledgerImmutable = array_map(
            static function (array $row): array {
                unset($row['warehouse_id']);
                return $row;
            },
            $ledgers,
        );
        $authorizationMapping = array_map(
            static function (array $row): array {
                unset($row['writer_enabled'], $row['authorization_version']);
                return $row;
            },
            $authorizations,
        );

        return [
            'ok' => true,
            'error' => null,
            'table_presence' => $presence,
            'physical_tables' => $tables,
            'schema_fingerprints' => $this->schemaFingerprints($pdo, $tables),
            'snapshots' => [
                'stock' => $this->snapshot($stocks, 'stock_id'),
                'reservation_immutable' => $this->snapshot(
                    $reservationImmutable,
                    'reservation_id',
                ),
                'reservation_state' => $this->snapshot($reservations, 'reservation_id'),
                'ledger_immutable' => $this->snapshot($ledgerImmutable, 'ledger_id'),
                'ledger_state' => $this->snapshot($ledgers, 'ledger_id'),
                'warehouse' => $this->snapshot($warehouses, 'warehouse_id'),
                'authorization_mapping' => $this->snapshot(
                    $authorizationMapping,
                    'authorization_id',
                ),
                'authorization_state' => $this->snapshot(
                    $authorizations,
                    'authorization_id',
                ),
                'quota' => $this->snapshot($quotas, 'quota_id'),
            ],
            'stocks' => $stocks,
            'reservations' => $reservations,
            'ledgers' => $ledgers,
            'warehouses' => $warehouses,
            'authorizations' => $authorizations,
            'quotas' => $quotas,
        ];
    }

    /**
     * @param array<string, string> $tables
     * @return array<string, string>
     */
    private function schemaFingerprints(\PDO $pdo, array $tables): array
    {
        $out = [];
        $statement = $pdo->prepare(
            'SELECT column_name,data_type,is_nullable,column_default'
            . ' FROM information_schema.columns'
            . " WHERE table_schema='public' AND table_name=:table_name"
            . ' ORDER BY ordinal_position ASC',
        );
        foreach ($tables as $logical => $physical) {
            $statement->execute(['table_name' => $physical]);
            $out[$logical] = $this->digest($statement->fetchAll());
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{count:int,watermark:int,digest:string}
     */
    private function snapshot(array $rows, string $primaryKey): array
    {
        $watermark = 0;
        foreach ($rows as $row) {
            $watermark = max($watermark, (int) ($row[$primaryKey] ?? 0));
        }

        return [
            'count' => count($rows),
            'watermark' => $watermark,
            'digest' => $this->digest($rows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function digest(array $rows): string
    {
        return hash(
            'sha256',
            json_encode(
                $rows,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function tables(array $config): array
    {
        $prefix = (string) ($config['prefix'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new \RuntimeException('mig_p3a_warehouse_probe_invalid_table_prefix');
        }

        return [
            'stock' => $prefix . 'weline_inventory_stock',
            'reservation' => $prefix . 'weline_inventory_reservation',
            'ledger' => $prefix . 'weline_inventory_ledger',
            'warehouse' => $prefix . 'weline_inventory_warehouse',
            'authorization' => $prefix . 'weline_inventory_warehouse_store_authorization',
            'quota' => $prefix . 'weline_inventory_warehouse_quota',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(array $config): \PDO
    {
        $type = strtolower(trim((string) ($config['type'] ?? 'pgsql')));
        if ($type !== 'pgsql') {
            throw new \RuntimeException('mig_p3a_warehouse_probe_requires_pgsql:' . $type);
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
     * @param array<string, int|string> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(\PDO $pdo, string $sql, array $params = []): ?array
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
            throw new \RuntimeException('mig_p3a_warehouse_probe_query_failed');
        }
        $rows = $statement->fetchAll();

        return is_array($rows) ? array_values($rows) : [];
    }
}
