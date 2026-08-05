<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;

/**
 * Checkout→Order 单写切流（MOD-MIG-P2-ORDER / TEST-MIG-P2-01..07）。
 *
 * 默认路径只接受 migration registry 登记的隔离 clone。apply 把不可变
 * manifest 与 cutover state 写入 journal；verify/rollback 必须从 journal
 * 重建 gate，因而可在新的 PHP/CLI 进程中复核，绝不依赖进程内存续命。
 */
final class OrderCutoverMigrationService
{
    public const PHASE = 'p2-order';
    public const ERROR_SHARED_DB = 'mig_p2_order_requires_isolated_database';
    public const ERROR_CLONE_NOT_REGISTERED = 'mig_p2_order_clone_not_registered';
    public const ERROR_SHARD_NOT_READY = 'mig_p2_order_product_shard_not_ready';
    public const ERROR_TOKEN = 'mig_p2_order_token_required';
    public const ERROR_CHECKPOINT = 'mig_p2_order_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p2_order_checkpoint_fingerprint_mismatch';

    /**
     * @var array{
     *   legacy: array<string, array<string, mixed>>,
     *   legacy_mutations: int,
     *   product_shard_ready: bool,
     *   production_on_token: string,
     *   audit: list<array<string, mixed>>
     * }
     */
    private array $memory = [
        'legacy' => [],
        'legacy_mutations' => 0,
        'product_shard_ready' => false,
        'production_on_token' => '',
        'audit' => [],
    ];

    private OrderCutoverGate $gate;
    private OrderWriterGuard $guard;
    private OrderFacade $facade;
    private OrderCompatibilityReader $reader;
    private ?string $lastCheckpointId = null;

    /** @var array<string, mixed>|null */
    private ?array $lastTargetDb = null;

    public function __construct(
        private readonly ?DatabaseFingerprintGuard $fingerprintGuard = null,
        private readonly ?MigrationCheckpointService $checkpointService = null,
        ?OrderCutoverGate $gate = null,
        ?OrderFacade $facade = null,
        ?OrderWriterGuard $guard = null,
        ?OrderCompatibilityReader $reader = null,
        private readonly ?OrderCutoverDatabaseProbe $databaseProbe = null,
        private readonly bool $memoryProbe = false,
    ) {
        if ($gate !== null && $guard !== null && $guard->gate() !== $gate) {
            throw new \InvalidArgumentException('mig_p2_order_gate_guard_mismatch');
        }
        $this->guard = $guard ?? new OrderWriterGuard($gate ?? new OrderCutoverGate());
        $this->gate = $this->guard->gate();
        $this->facade = $facade ?? OrderFacade::forTesting(writerGuard: $this->guard);
        $this->reader = $reader ?? OrderCompatibilityReader::forTesting($this->facade);
    }

    public static function forTesting(?string $journalDir = null): self
    {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? (\sys_get_temp_dir() . '/mig_p2ord_' . \uniqid('', true)),
        );
        $checkpoint = new MigrationCheckpointService($guard, $store);

        return new self(
            fingerprintGuard: $guard,
            checkpointService: $checkpoint,
            memoryProbe: true,
        );
    }

    public function gate(): OrderCutoverGate
    {
        return $this->gate;
    }

    public function guard(): OrderWriterGuard
    {
        return $this->guard;
    }

    public function facade(): OrderFacade
    {
        return $this->facade;
    }

    public function reader(): OrderCompatibilityReader
    {
        return $this->reader;
    }

    public function setProductShardReady(bool $ready): void
    {
        $this->memory['product_shard_ready'] = $ready;
    }

    public function setProductionOnToken(string $token): void
    {
        $this->memory['production_on_token'] = trim($token);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function seedLegacyOrder(string $orderNumber, array $row = []): array
    {
        $normalized = array_merge([
            'order_number' => $orderNumber,
            'status' => 'pending',
            'currency' => 'CNY',
            'website_id' => 0,
            'store_id' => 0,
            'subtotal' => 1.0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 1.0,
            'paid' => false,
        ], $row, ['order_number' => $orderNumber]);
        $this->memory['legacy'][$orderNumber] = $normalized;
        $this->reader->seedLegacy($orderNumber, $normalized);

        return $normalized;
    }

    /**
     * 模拟 legacy Checkout mutation；真实路径由 AssertLegacyCheckoutWriter
     * 在业务写前调用同一个 writer guard。
     *
     * @return array<string, mixed>
     */
    public function attemptLegacyMutation(string $orderNumber, string $subject = 'website:0'): array
    {
        $this->guard->assertLegacyCheckoutWritable($subject);
        if (!isset($this->memory['legacy'][$orderNumber])) {
            throw new \InvalidArgumentException('mig_p2_order_legacy_missing');
        }
        $this->memory['legacy'][$orderNumber]['status'] = 'mutated';
        $this->memory['legacy_mutations']++;
        $this->reader->seedLegacy($orderNumber, $this->memory['legacy'][$orderNumber]);

        return $this->memory['legacy'][$orderNumber];
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function preflight(?array $targetDb = null): array
    {
        if ($this->memoryProbe) {
            $snapshot = $this->memorySnapshot();
            return $this->preflightResult($snapshot, null);
        }

        try {
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $snapshot = ($this->databaseProbe ?? new OrderCutoverDatabaseProbe())->inspect($db);
            $this->lastTargetDb = $db;

            return $this->preflightResult($snapshot, $fingerprint);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $e->getMessage(),
                'apply_ready' => false,
                'shared_db_apply_forbidden' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function apply(?array $targetDb = null, string $productionOnToken = ''): array
    {
        $db = $this->requireIsolatedTarget($targetDb);
        $guard = $this->resolveFingerprintGuard($db);
        $fingerprint = $guard->assertIsolatedDatabase($db);
        $token = trim($productionOnToken !== ''
            ? $productionOnToken
            : $this->memory['production_on_token']);
        if ($token === '') {
            return ['ok' => false, 'error' => self::ERROR_TOKEN, 'phase' => self::PHASE];
        }

        $preflight = $this->memoryProbe
            ? $this->preflightResult($this->memorySnapshot(), $fingerprint)
            : $this->preflight($db);
        if (empty($preflight['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($preflight['error'] ?? 'mig_p2_order_preflight_failed'),
                'phase' => self::PHASE,
                'preflight' => $preflight,
            ];
        }
        if (empty($preflight['product_shard_ready'])) {
            return [
                'ok' => false,
                'error' => self::ERROR_SHARD_NOT_READY,
                'phase' => self::PHASE,
                'preflight' => $preflight,
            ];
        }

        $checkpoint = $this->checkpoint($guard);
        $checkpointId = 'p2ord-' . \gmdate('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(3)), 0, 6);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-apply',
            'repo' => 'WelineFramework',
            'branch' => 'working-tree',
            'commit' => 'current-source',
            'connector_fingerprint' => $fingerprint,
            'schema_fingerprints' => (array) ($preflight['schema_fingerprints'] ?? []),
            'row_counts' => [
                'product_shard_registry' => (int) ($preflight['product_shard_count'] ?? 0),
                'legacy_order' => (int) ($preflight['legacy_order_count'] ?? 0),
                'new_order' => (int) ($preflight['new_order_count'] ?? 0),
                'checkout_group' => (int) ($preflight['checkout_group_count'] ?? 0),
            ],
            'row_hashes' => [
                'product_shard_registry' => (string) ($preflight['product_shard_digest'] ?? ''),
                'legacy_order' => (string) ($preflight['legacy_order_digest'] ?? ''),
            ],
            'watermarks' => [
                'legacy_order' => (int) ($preflight['legacy_order_watermark'] ?? 0),
                'new_order' => (int) ($preflight['new_order_watermark'] ?? 0),
                'checkout_group' => (int) ($preflight['checkout_group_watermark'] ?? 0),
            ],
            'backup_ref' => 'clone:' . $db['database'],
            'created_at' => \gmdate('c'),
        ]);
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p2_order_preflight_snapshot', [
            'database' => (string) $db['database'],
            'fingerprint' => $fingerprint,
            'product_shard_ready' => true,
            'legacy_order_count' => (int) ($preflight['legacy_order_count'] ?? 0),
            'new_order_count' => (int) ($preflight['new_order_count'] ?? 0),
            'checkout_group_count' => (int) ($preflight['checkout_group_count'] ?? 0),
        ]);
        $checkpoint->applyGuard($db, $checkpointId, $manifest);

        $cutover = $this->gate->executeCutover([
            'production_on_token' => $token,
            'watermark' => (int) ($preflight['legacy_order_watermark'] ?? 0),
            'checkpoint_id' => $checkpointId,
        ]);
        $checkpoint->appendJournal($checkpointId, 'p2_order_cutover_done', [
            'checkpoint_id' => $checkpointId,
            'database' => (string) $db['database'],
            'fingerprint' => $fingerprint,
            'mode' => $cutover['mode'],
            'watermark' => $cutover['watermark'],
            'cutover_applied' => true,
            'legacy_writable' => false,
            'new_writable' => true,
            'single_writer' => 'weline_order',
        ]);

        $this->lastCheckpointId = $checkpointId;
        $this->lastTargetDb = $db;
        $this->memory['audit'][] = [
            'type' => 'apply',
            'checkpoint_id' => $checkpointId,
            'at' => time(),
        ];

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => (string) $db['database'],
            'fingerprint' => $fingerprint,
            'mode' => $this->gate->mode(),
            'cutover_applied' => $this->gate->isCutoverApplied(),
            'watermark' => $this->gate->watermark(),
            'legacy_writable' => $this->gate->legacyWritable('website:0'),
            'new_writable' => $this->gate->newWritable('website:0'),
            'legacy_order_count' => (int) ($preflight['legacy_order_count'] ?? 0),
            'new_order_count' => (int) ($preflight['new_order_count'] ?? 0),
            'single_writer' => 'weline_order',
        ];
    }

    /**
     * Fresh-process verify：从持久化 manifest/journal 重建 gate，再重新连接
     * 目标 clone 读取水位与摘要。
     *
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function verify(?array $targetDb = null, string $checkpointId = ''): array
    {
        try {
            $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $checkpointId = $this->resolveCheckpointId($checkpointId);
            $checkpoint = $this->checkpoint($guard);
            $fresh = $checkpoint->verifyFresh($checkpointId);
            $row = $checkpoint->store()?->load($checkpointId);
            if (empty($fresh['ok']) || $row === null) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'checkpoint_id' => $checkpointId,
                    'error' => (string) ($fresh['error'] ?? 'migration_checkpoint_missing'),
                    'journal' => $fresh,
                ];
            }
            $manifest = MigrationManifest::fromArray($row['manifest']);
            $diffs = [];
            if (!hash_equals($manifest->connectorFingerprint, $fingerprint)) {
                $diffs[] = [
                    'code' => self::ERROR_FINGERPRINT,
                    'expected' => $manifest->connectorFingerprint,
                    'actual' => $fingerprint,
                ];
            }

            $this->rehydrateGate($row['journal']);
            if (!$this->gate->isCutoverApplied()) {
                $diffs[] = ['code' => 'cutover_not_applied'];
            }
            if ($this->gate->legacyWritable('website:0')) {
                $diffs[] = ['code' => 'legacy_still_writable'];
            }

            $current = $this->memoryProbe
                ? $this->memorySnapshot()
                : ($this->databaseProbe ?? new OrderCutoverDatabaseProbe())->inspect($db);
            if (empty($current['ok'])) {
                $diffs[] = [
                    'code' => 'database_probe_failed',
                    'error' => (string) ($current['error'] ?? 'unknown'),
                ];
            } else {
                $this->compareFreshSnapshot($manifest, $current, $diffs);
            }

            return [
                'ok' => $diffs === [],
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'diff_count' => count($diffs),
                'diffs' => $diffs,
                'mode' => $this->gate->mode(),
                'cutover_applied' => $this->gate->isCutoverApplied(),
                'watermark' => $this->gate->watermark(),
                'legacy_order_count' => (int) ($current['legacy_order_count'] ?? 0),
                'new_order_count' => (int) ($current['new_order_count'] ?? 0),
                'legacy_mutations' => $this->memory['legacy_mutations'],
                'legacy_writable' => $this->gate->legacyWritable('website:0'),
                'new_writable' => $this->gate->newWritable('website:0'),
                'fresh_journal' => $fresh,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Safe rollback：有新单时拒绝回 off；shadow 关闭新交易；任何路径都不恢复旧 writer。
     *
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function rollbackUi(
        string $targetMode = OrderCutoverGate::MODE_SHADOW,
        ?array $targetDb = null,
        string $checkpointId = '',
    ): array {
        try {
            $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $guard->assertIsolatedDatabase($db);
            $checkpointId = $this->resolveCheckpointId($checkpointId);
            $checkpoint = $this->checkpoint($guard);
            $row = $checkpoint->store()?->load($checkpointId);
            if ($row === null) {
                throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
            }
            $manifest = MigrationManifest::fromArray($row['manifest']);
            $this->rehydrateGate($row['journal']);
            $current = $this->memoryProbe
                ? $this->memorySnapshot()
                : ($this->databaseProbe ?? new OrderCutoverDatabaseProbe())->inspect($db);
            if (empty($current['ok'])) {
                throw new \RuntimeException((string) ($current['error'] ?? 'mig_p2_order_probe_failed'));
            }
            $baselineNew = (int) ($manifest->rowCounts['new_order'] ?? 0);
            $hasNew = (int) ($current['new_order_count'] ?? 0) > $baselineNew
                || $this->facade->writeCount() > 0;

            try {
                $result = $this->gate->rollbackUiMode($targetMode, $hasNew);
            } catch (OrderFacadeConflictException $e) {
                return [
                    'ok' => false,
                    'error' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'checkpoint_id' => $checkpointId,
                    'has_new_orders' => $hasNew,
                    'mode' => $this->gate->mode(),
                    'legacy_writable' => $this->gate->legacyWritable('website:0'),
                    'new_readable' => true,
                    'continue_forward' => true,
                ];
            }

            $checkpoint->appendJournal($checkpointId, 'p2_order_controlled_rollback', [
                'checkpoint_id' => $checkpointId,
                'mode' => $result['mode'],
                'has_new_orders' => $hasNew,
                'legacy_writable' => false,
                'new_writable' => $this->gate->newWritable('website:0'),
                'new_readable' => true,
                'continue_forward' => true,
            ]);
            $this->memory['audit'][] = [
                'type' => 'rollback_ui',
                'target' => $targetMode,
                'at' => time(),
                'has_new_orders' => $hasNew,
            ];

            return [
                'ok' => true,
                'checkpoint_id' => $checkpointId,
                'mode' => $result['mode'],
                'has_new_orders' => $hasNew,
                'legacy_writable' => $this->gate->legacyWritable('website:0'),
                'new_writable' => $this->gate->newWritable('website:0'),
                'new_readable' => true,
                'forbid_legacy_writer_restored' => !$this->gate->legacyWritable('website:0'),
                'continue_forward' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'error' => $e->getMessage(),
                'continue_forward' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createSandboxNewOrder(string $idempotencyKey): array
    {
        if (!$this->memoryProbe) {
            throw new \LogicException('mig_p2_order_sandbox_create_is_test_only');
        }
        $command = new CreateCheckoutGroupCommand(
            idempotencyKey: $idempotencyKey,
            requestHash: hash('sha256', $idempotencyKey),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'MIG-SKU', 'qty_minor' => 1, 'unit_price_minor' => 100]],
        );
        $created = $this->facade->create($command);
        $uuid = $created->orderUuids[0] ?? '';

        return [
            'checkout_group_uuid' => $created->checkoutGroupUuid,
            'order_uuid' => $uuid,
            'legacy_table_has_new_fact' => false,
            'write_count' => $this->facade->writeCount(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function legacyOrders(): array
    {
        return $this->memory['legacy'];
    }

    public function legacyMutationCount(): int
    {
        return $this->memory['legacy_mutations'];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function preflightResult(array $snapshot, ?string $fingerprint): array
    {
        $probeOk = !array_key_exists('ok', $snapshot) || $snapshot['ok'] === true;
        $shardReady = !empty($snapshot['product_shard_ready']);
        $error = null;
        if (!$probeOk) {
            $error = (string) ($snapshot['error'] ?? 'mig_p2_order_probe_failed');
        } elseif (!$shardReady) {
            $error = self::ERROR_SHARD_NOT_READY;
        }

        return array_merge($snapshot, [
            'ok' => $probeOk,
            'phase' => self::PHASE,
            'fingerprint' => $fingerprint,
            'mode' => $this->gate->mode(),
            'cutover_applied' => $this->gate->isCutoverApplied(),
            'legacy_writable' => $this->gate->legacyWritable('website:0'),
            'new_writable' => $this->gate->newWritable('website:0'),
            'shared_db_apply_forbidden' => true,
            'apply_ready' => $probeOk && $shardReady,
            'error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function memorySnapshot(): array
    {
        $legacyRows = array_values($this->memory['legacy']);
        $legacyCount = count($legacyRows);
        $newCount = $this->facade->writeCount();
        $productRows = [[
            'website_id' => 0,
            'status' => $this->memory['product_shard_ready'] ? 'ready' : 'unprovisioned',
            'fingerprint' => $this->memory['product_shard_ready'] ? 'memory-ready' : '',
            'schema_version' => 'test',
        ]];

        return [
            'ok' => true,
            'error' => null,
            'schema_fingerprints' => [
                'product_shard_registry' => hash('sha256', 'memory-product-schema'),
                'weline_checkout_order' => hash('sha256', 'memory-legacy-schema'),
                'weline_order' => hash('sha256', 'memory-order-schema'),
                'weline_checkout_group' => hash('sha256', 'memory-group-schema'),
            ],
            'product_shard_ready' => $this->memory['product_shard_ready'],
            'product_shard_count' => 1,
            'ready_website_ids' => $this->memory['product_shard_ready'] ? [0] : [],
            'blocked_shards' => $this->memory['product_shard_ready'] ? [] : [[
                'website_id' => 0,
                'status' => 'unprovisioned',
                'fingerprint_present' => false,
            ]],
            'product_shard_digest' => $this->digest($productRows),
            'legacy_order_count' => $legacyCount,
            'legacy_order_watermark' => $legacyCount,
            'legacy_order_digest' => $this->digest($legacyRows),
            'new_order_count' => $newCount,
            'new_order_watermark' => $newCount,
            'new_order_digest' => hash('sha256', (string) $newCount),
            'checkout_group_count' => $newCount,
            'checkout_group_watermark' => $newCount,
            'checkout_group_digest' => hash('sha256', 'group:' . $newCount),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param list<array<string, mixed>> $diffs
     */
    private function compareFreshSnapshot(
        MigrationManifest $manifest,
        array $current,
        array &$diffs,
    ): void {
        if (empty($current['product_shard_ready'])) {
            $diffs[] = ['code' => self::ERROR_SHARD_NOT_READY];
        }
        $expectedSchemas = $manifest->schemaFingerprints;
        $actualSchemas = (array) ($current['schema_fingerprints'] ?? []);
        foreach ($expectedSchemas as $table => $hash) {
            if (!isset($actualSchemas[$table]) || !hash_equals((string) $hash, (string) $actualSchemas[$table])) {
                $diffs[] = ['code' => 'schema_fingerprint_changed', 'table' => (string) $table];
            }
        }

        $expectedLegacyCount = (int) ($manifest->rowCounts['legacy_order'] ?? -1);
        $actualLegacyCount = (int) ($current['legacy_order_count'] ?? -2);
        if ($expectedLegacyCount !== $actualLegacyCount) {
            $diffs[] = [
                'code' => 'legacy_order_count_changed',
                'expected' => $expectedLegacyCount,
                'actual' => $actualLegacyCount,
            ];
        }
        $expectedLegacyDigest = (string) ($manifest->rowHashes['legacy_order'] ?? '');
        $actualLegacyDigest = (string) ($current['legacy_order_digest'] ?? '');
        if ($expectedLegacyDigest === '' || !hash_equals($expectedLegacyDigest, $actualLegacyDigest)) {
            $diffs[] = ['code' => 'legacy_order_digest_changed'];
        }
        $expectedProductDigest = (string) ($manifest->rowHashes['product_shard_registry'] ?? '');
        $actualProductDigest = (string) ($current['product_shard_digest'] ?? '');
        if ($expectedProductDigest === '' || !hash_equals($expectedProductDigest, $actualProductDigest)) {
            $diffs[] = ['code' => 'product_shard_registry_changed'];
        }

        foreach ([
            'new_order' => 'new_order_count',
            'checkout_group' => 'checkout_group_count',
        ] as $manifestKey => $currentKey) {
            $before = (int) ($manifest->rowCounts[$manifestKey] ?? 0);
            $after = (int) ($current[$currentKey] ?? 0);
            if ($after < $before) {
                $diffs[] = [
                    'code' => $manifestKey . '_count_regressed',
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     */
    private function rehydrateGate(array $journal): void
    {
        if (!$this->gate->isCutoverApplied()) {
            foreach ($journal as $entry) {
                if (($entry['event'] ?? '') !== 'p2_order_cutover_done') {
                    continue;
                }
                $detail = (array) ($entry['detail'] ?? []);
                $this->gate->executeCutover([
                    'production_on_token' => 'verified-journal:' . (string) ($detail['checkpoint_id'] ?? ''),
                    'watermark' => (int) ($detail['watermark'] ?? 0),
                    'checkpoint_id' => (string) ($detail['checkpoint_id'] ?? ''),
                ]);
                break;
            }
        }
        if (!$this->gate->isCutoverApplied()) {
            return;
        }
        foreach ($journal as $entry) {
            if (($entry['event'] ?? '') !== 'p2_order_controlled_rollback') {
                continue;
            }
            $detail = (array) ($entry['detail'] ?? []);
            $this->gate->rollbackUiMode(
                (string) ($detail['mode'] ?? OrderCutoverGate::MODE_SHADOW),
                (bool) ($detail['has_new_orders'] ?? false),
            );
        }
    }

    private function checkpoint(DatabaseFingerprintGuard $guard): MigrationCheckpointService
    {
        return $this->checkpointService ?? new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(),
        );
    }

    private function resolveCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId !== '') {
            return $checkpointId;
        }
        if ($this->lastCheckpointId !== null && $this->lastCheckpointId !== '') {
            return $this->lastCheckpointId;
        }
        throw new \RuntimeException(self::ERROR_CHECKPOINT . ': pass --checkpoint=ID');
    }

    /**
     * @param array<string, mixed> $targetDb
     */
    private function resolveFingerprintGuard(array $targetDb): DatabaseFingerprintGuard
    {
        if ($this->fingerprintGuard !== null) {
            return $this->fingerprintGuard;
        }

        /** @var MigrationCloneService $clones */
        $clones = ObjectManager::getInstance(MigrationCloneService::class);
        $database = (string) ($targetDb['database'] ?? '');
        $registered = false;
        foreach ($clones->list() as $handle) {
            if ($handle->database === $database) {
                $registered = true;
                break;
            }
        }
        if (!$registered) {
            throw new \RuntimeException(self::ERROR_CLONE_NOT_REGISTERED . ':' . $database);
        }

        return $clones->guardedFingerprint();
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array{hostname:string,hostport:string,database:string,username:string,password:string,type:string,prefix:string}
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        $database = trim((string) ($targetDb['database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_* (create via php bin/w mig:foundation clone-create --mode=full --purpose=p2order)',
            );
        }
        $config = [
            'type' => (string) ($targetDb['type'] ?? 'pgsql'),
            'hostname' => (string) ($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string) ($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string) ($targetDb['username'] ?? 'weline'),
            'password' => (string) ($targetDb['password'] ?? ''),
            'prefix' => (string) ($targetDb['prefix'] ?? ''),
        ];
        ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())->assertIsolatedDatabase($config);

        return $config;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function digest(array $rows): string
    {
        return hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
