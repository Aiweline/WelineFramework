<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * MIG-P2-ORDER 的只读数据库探针。
 *
 * 仅允许静态表/列清单，所有连接均由已通过 migration fingerprint guard 的
 * 隔离 clone 配置创建。探针不执行 DDL/DML，也不返回订单明细。
 */
final class OrderCutoverDatabaseProbe
{
    /**
     * @param array{
     *   type?:string,
     *   hostname?:string,
     *   hostport?:int|string,
     *   database?:string,
     *   username?:string,
     *   password?:string,
     *   prefix?:string
     * } $config
     * @return array<string, mixed>
     */
    public function inspect(array $config): array
    {
        $pdo = $this->connect($config);
        $prefix = (string) ($config['prefix'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new \RuntimeException('mig_p2_order_probe_invalid_table_prefix');
        }
        $requiredTables = [
            'product_shard_registry' => $prefix . 'product_shard_registry',
            'weline_checkout_order' => $prefix . 'weline_checkout_order',
            'weline_order' => $prefix . 'weline_order',
            'weline_checkout_group' => $prefix . 'weline_checkout_group',
        ];
        $tablePresence = [];
        foreach ($requiredTables as $logical => $physical) {
            $tablePresence[$logical] = $this->tableExists($pdo, $physical);
        }
        $missingTables = array_keys(array_filter(
            $tablePresence,
            static fn (bool $exists): bool => !$exists,
        ));
        if ($missingTables !== []) {
            return [
                'ok' => false,
                'error' => 'mig_p2_order_required_tables_missing',
                'missing_tables' => $missingTables,
                'table_presence' => $tablePresence,
                'physical_tables' => $requiredTables,
            ];
        }

        $shards = $this->fetchAll(
            $pdo,
            'SELECT website_id, status, fingerprint, schema_version '
            . 'FROM ' . $requiredTables['product_shard_registry'] . ' ORDER BY website_id ASC',
        );
        $readyWebsites = [];
        $blockedShards = [];
        foreach ($shards as $row) {
            $websiteId = (int) ($row['website_id'] ?? -1);
            $status = (string) ($row['status'] ?? '');
            $fingerprint = trim((string) ($row['fingerprint'] ?? ''));
            if ($websiteId >= 0 && $status === 'ready' && $fingerprint !== '') {
                $readyWebsites[] = $websiteId;
                continue;
            }
            $blockedShards[] = [
                'website_id' => $websiteId,
                'status' => $status,
                'fingerprint_present' => $fingerprint !== '',
            ];
        }
        $productShardReady = $shards !== []
            && in_array(0, $readyWebsites, true)
            && $blockedShards === [];

        $legacy = $this->tableSnapshot(
            $pdo,
            $requiredTables['weline_checkout_order'],
            'order_id',
            [
                'order_id',
                'order_number',
                'status',
                'payment_status',
                'subtotal',
                'shipping_amount',
                'tax_amount',
                'total_amount',
                'currency',
            ],
        );
        $orders = $this->tableSnapshot(
            $pdo,
            $requiredTables['weline_order'],
            'order_id',
            [
                'order_id',
                'order_uuid',
                'checkout_group_uuid',
                'order_number',
                'status',
                'payment_status',
                'grand_total',
                'currency',
                'website_id',
                'store_id',
            ],
        );
        $groups = $this->tableSnapshot(
            $pdo,
            $requiredTables['weline_checkout_group'],
            'checkout_group_id',
            [
                'checkout_group_id',
                'checkout_group_uuid',
                'status',
                'grand_total_minor',
                'website_id',
                'store_id',
            ],
        );

        return [
            'ok' => true,
            'error' => null,
            'table_presence' => $tablePresence,
            'physical_tables' => $requiredTables,
            'schema_fingerprints' => $this->schemaFingerprints($pdo, $requiredTables),
            'product_shard_ready' => $productShardReady,
            'product_shard_count' => count($shards),
            'ready_website_ids' => $readyWebsites,
            'blocked_shards' => $blockedShards,
            'product_shard_digest' => $this->rowsDigest($shards),
            'legacy_order_count' => $legacy['count'],
            'legacy_order_watermark' => $legacy['watermark'],
            'legacy_order_digest' => $legacy['digest'],
            'new_order_count' => $orders['count'],
            'new_order_watermark' => $orders['watermark'],
            'new_order_digest' => $orders['digest'],
            'checkout_group_count' => $groups['count'],
            'checkout_group_watermark' => $groups['watermark'],
            'checkout_group_digest' => $groups['digest'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(array $config): \PDO
    {
        $type = strtolower(trim((string) ($config['type'] ?? 'pgsql')));
        if ($type !== 'pgsql') {
            throw new \RuntimeException('mig_p2_order_probe_requires_pgsql:' . $type);
        }
        $host = (string) ($config['hostname'] ?? '127.0.0.1');
        $port = (string) ($config['hostport'] ?? '5432');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database;

        return new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_PERSISTENT => false,
        ]);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT to_regclass(:table_name)');
        $statement->execute(['table_name' => 'public.' . $table]);

        return $statement->fetchColumn() !== null;
    }

    /**
     * @param array<string, string> $tables logical => physical
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
    private function tableSnapshot(
        \PDO $pdo,
        string $table,
        string $primaryKey,
        array $columns,
    ): array {
        $sql = 'SELECT ' . implode(', ', $columns)
            . ' FROM ' . $table
            . ' ORDER BY ' . $primaryKey . ' ASC';
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('mig_p2_order_probe_query_failed:' . $table);
        }
        $context = hash_init('sha256');
        $count = 0;
        $watermark = 0;
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $count++;
            $watermark = max($watermark, (int) ($row[$primaryKey] ?? 0));
            hash_update(
                $context,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
        }

        return [
            'count' => $count,
            'watermark' => $watermark,
            'digest' => hash_final($context),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(\PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('mig_p2_order_probe_query_failed');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
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
