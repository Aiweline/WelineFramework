<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\Warehouse;

/**
 * Registered-clone migration from P2 Store inventory to the default logical
 * Warehouse. Production paths are database-backed; memory is an explicit pure
 * mapping harness only.
 */
final class WarehouseMigrationService
{
    public const PHASE = 'p3a-warehouse';
    public const ERROR_SHARED_DB = 'mig_p3a_warehouse_requires_isolated_database';
    public const ERROR_CLONE_NOT_REGISTERED = 'mig_p3a_warehouse_clone_not_registered';
    public const ERROR_CHECKPOINT = 'mig_p3a_warehouse_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p3a_warehouse_checkpoint_fingerprint_mismatch';
    public const ERROR_CONFLICT = 'mig_p3a_warehouse_conflicts_block_apply';
    public const ERROR_MODE_OFF = 'mig_p3a_warehouse_rollout_off';
    public const ERROR_APPLY_MODE = 'mig_p3a_warehouse_apply_requires_mode_off';
    public const ERROR_MODE_CROSS = 'mig_p3a_warehouse_normal_test_cross';

    public const MAP_STATUS_ALREADY = 'already';
    public const MAP_STATUS_MAPPED = 'mapped';

    /** @var array<string, array<string, mixed>> */
    private array $memoryStocks = [];
    /** @var array<string, array<string, mixed>> */
    private array $memoryReservations = [];
    /** @var array<string, array<string, mixed>> */
    private array $memoryWarehouseStocks = [];
    /** @var array<string, array<string, mixed>> */
    private array $memoryMappedReservations = [];
    /** @var array<string, int> */
    private array $memoryMapping = [];
    private string $memoryMode = 'off';
    private bool $rolloutWritable = true;
    private ?string $lastCheckpointId = null;
    /** @var array<string, mixed>|null */
    private ?array $lastTargetDb = null;
    private readonly InventoryAvailabilityCalculator $availability;

    public function __construct(
        private readonly ?DatabaseFingerprintGuard $fingerprintGuard = null,
        private readonly ?MigrationCheckpointService $checkpointService = null,
        private readonly ?WarehouseMigrationDatabaseProbe $databaseProbe = null,
        private readonly bool $memoryProbe = false,
    ) {
        $this->availability = new InventoryAvailabilityCalculator();
    }

    public static function forTesting(?string $journalDir = null): self
    {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? sys_get_temp_dir() . '/mig_p3a_' . uniqid('', true),
        );

        return new self(
            fingerprintGuard: $guard,
            checkpointService: new MigrationCheckpointService($guard, $store),
            memoryProbe: true,
        );
    }

    public function setRolloutWritable(bool $writable): void
    {
        $this->rolloutWritable = $writable;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function seedStock(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $onHandMinor,
        int $reservedMinor = 0,
        int $deductedMinor = 0,
        string $storeMode = Warehouse::MODE_NORMAL,
        array $extra = [],
    ): array {
        if (!$this->memoryProbe) {
            throw new \LogicException('migration_memory_seed_disabled');
        }
        $available = $this->availability->availableMinor(
            (string) ($extra['strategy'] ?? InventoryAvailabilityCalculator::STRATEGY_STRICT),
            $onHandMinor,
            $reservedMinor,
            (int) ($extra['oversell_allowance'] ?? 0),
            (int) ($extra['preorder_allowance'] ?? 0),
        );
        $row = array_merge([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'offer_id' => $offerId,
            'store_mode' => $storeMode,
            'strategy' => (string) (
                $extra['strategy'] ?? InventoryAvailabilityCalculator::STRATEGY_STRICT
            ),
            'on_hand_minor' => $onHandMinor,
            'reserved_minor' => $reservedMinor,
            'deducted_minor' => $deductedMinor,
            'available_minor' => $available,
            'oversell_allowance' => (int) ($extra['oversell_allowance'] ?? 0),
            'preorder_allowance' => (int) ($extra['preorder_allowance'] ?? 0),
        ], $extra);
        $this->memoryStocks[$this->stockKey($websiteId, $storeId, $offerId)] = $row;

        return $row;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function seedReservation(
        string $reservationUuid,
        int $websiteId,
        int $storeId,
        int $offerId,
        int $qtyMinor,
        string $storeMode = Warehouse::MODE_NORMAL,
        array $extra = [],
    ): array {
        if (!$this->memoryProbe) {
            throw new \LogicException('migration_memory_seed_disabled');
        }
        $row = array_merge([
            'reservation_uuid' => $reservationUuid,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'offer_id' => $offerId,
            'qty_minor' => $qtyMinor,
            'quantity_minor' => $qtyMinor,
            'store_mode' => $storeMode,
            'warehouse_id' => null,
            'state' => 'reserved',
        ], $extra);
        $this->memoryReservations[$reservationUuid] = $row;

        return $row;
    }

    /**
     * @param array<string, mixed> $stock
     * @return array<string, mixed>
     */
    public function mapStock(array $stock): array
    {
        $websiteId = (int) $stock['website_id'];
        $storeId = (int) $stock['store_id'];
        $offerId = (int) $stock['offer_id'];
        $mode = (string) ($stock['store_mode'] ?? Warehouse::MODE_NORMAL);
        $warehouseId = $mode === Warehouse::MODE_TEST ? 200 : 100;
        $key = $this->stockKey($websiteId, $storeId, $offerId);
        $status = isset($this->memoryMapping[$key])
            ? self::MAP_STATUS_ALREADY
            : self::MAP_STATUS_MAPPED;

        return [
            'status' => $status,
            'warehouse_id' => $warehouseId,
            'warehouse_stock' => [
                'warehouse_id' => $warehouseId,
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'offer_id' => $offerId,
                'on_hand_minor' => (int) $stock['on_hand_minor'],
                'reserved_minor' => (int) $stock['reserved_minor'],
                'deducted_minor' => (int) ($stock['deducted_minor'] ?? 0),
                'available_minor' => (int) $stock['available_minor'],
                'legacy_stock_key' => $key,
                'compatibility' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    public function mapReservation(array $reservation): array
    {
        $uuid = (string) $reservation['reservation_uuid'];
        $mode = (string) ($reservation['store_mode'] ?? Warehouse::MODE_NORMAL);
        $warehouseId = $mode === Warehouse::MODE_TEST ? 200 : 100;

        return [
            'status' => isset($this->memoryMappedReservations[$uuid])
                ? self::MAP_STATUS_ALREADY
                : self::MAP_STATUS_MAPPED,
            'warehouse_id' => $warehouseId,
            'row' => array_merge($reservation, [
                'warehouse_id' => $warehouseId,
                'compatibility' => true,
            ]),
        ];
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function preflight(?array $targetDb = null): array
    {
        try {
            if ($this->memoryProbe) {
                return $this->memoryPreflight();
            }
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $snapshot = ($this->databaseProbe ?? new WarehouseMigrationDatabaseProbe())
                ->inspect($db);

            return $this->publicPreflight(
                $this->buildPreflight($snapshot, $fingerprint),
            );
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $exception->getMessage(),
                'apply_ready' => false,
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function apply(?array $targetDb = null): array
    {
        if (!$this->rolloutWritable) {
            return ['ok' => false, 'phase' => self::PHASE, 'error' => self::ERROR_MODE_OFF];
        }
        try {
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->memoryProbe
                ? ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())
                : $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            if ($this->memoryProbe) {
                return $this->applyMemory($db, $guard, $fingerprint);
            }

            $probe = $this->databaseProbe ?? new WarehouseMigrationDatabaseProbe();
            $preflight = $this->buildPreflight($probe->inspect($db), $fingerprint);
            if (empty($preflight['ok']) || (int) $preflight['conflict_count'] > 0) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'error' => (string) ($preflight['error'] ?? self::ERROR_CONFLICT),
                    'conflict_count' => (int) $preflight['conflict_count'],
                    'conflicts' => array_slice((array) $preflight['conflicts'], 0, 100),
                    'mapped' => 0,
                ];
            }
            if ((int) ($preflight['writer_enabled_count'] ?? 0) > 0) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'error' => self::ERROR_APPLY_MODE,
                    'mode' => (string) ($preflight['mode'] ?? 'allowlist'),
                    'writer_enabled_count' => (int) $preflight['writer_enabled_count'],
                    'mapped' => 0,
                ];
            }

            $checkpointId = $this->newCheckpointId();
            $manifest = $this->manifest($checkpointId, $fingerprint, $db, $preflight);
            $checkpoint = $this->checkpoint($guard);
            $checkpoint->checkpoint($manifest);
            $checkpoint->appendJournal($checkpointId, 'p3a_warehouse_preflight_snapshot', [
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'stock_count' => (int) $preflight['stock_count'],
                'reservation_count' => (int) $preflight['reservation_count'],
                'ledger_count' => (int) $preflight['ledger_count'],
                'quota_plan_count' => count((array) $preflight['_quota_plans']),
            ]);
            $checkpoint->applyGuard($db, $checkpointId, $manifest);
            $write = $probe->applyMappings(
                $db,
                (array) $preflight['_quota_plans'],
                (array) $preflight['_reservation_plans'],
                (array) $preflight['_ledger_plans'],
                (array) $preflight['_snapshot'],
            );
            $checkpoint->appendJournal(
                $checkpointId,
                'p3a_warehouse_apply_done',
                $write + [
                    'database' => (string) $db['database'],
                    'fingerprint' => $fingerprint,
                    'history_retained' => true,
                    'writer_mode' => 'off',
                ],
            );
            $this->lastCheckpointId = $checkpointId;
            $this->lastTargetDb = $db;

            return [
                'ok' => true,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'quota_mapped' => (int) $write['quota_mapped'],
                'quota_already' => (int) $write['quota_already'],
                'reservations_mapped' => (int) $write['reservation_mapped'],
                'reservations_already' => (int) $write['reservation_already'],
                'ledgers_mapped' => (int) $write['ledger_mapped'],
                'ledgers_already' => (int) $write['ledger_already'],
                'mode' => 'off',
                'allowlist_ready' => false,
                'history_retained' => true,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $exception->getMessage(),
                'mapped' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function verify(?array $targetDb = null, string $checkpointId = ''): array
    {
        try {
            $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
            $guard = $this->memoryProbe
                ? ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())
                : $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $checkpointId = $this->resolveCheckpointId($checkpointId);
            $checkpoint = $this->checkpoint($guard);
            $fresh = $checkpoint->verifyFresh($checkpointId);
            $stored = $checkpoint->store()?->load($checkpointId);
            if (empty($fresh['ok']) || $stored === null) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'checkpoint_id' => $checkpointId,
                    'error' => (string) ($fresh['error'] ?? 'migration_checkpoint_missing'),
                    'fresh_journal' => $fresh,
                ];
            }
            $manifest = MigrationManifest::fromArray($stored['manifest']);
            $diffs = [];
            if (!hash_equals($manifest->connectorFingerprint, $fingerprint)) {
                $diffs[] = ['code' => self::ERROR_FINGERPRINT];
            }
            if ($this->memoryProbe) {
                $current = $this->memoryPreflight();
                $this->compareMemoryManifest($manifest, $current, $diffs);
            } else {
                $current = $this->buildPreflight(
                    ($this->databaseProbe ?? new WarehouseMigrationDatabaseProbe())
                        ->inspect($db),
                    $fingerprint,
                );
                $this->compareDatabaseManifest(
                    $manifest,
                    $current,
                    (array) $stored['journal'],
                    $diffs,
                );
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
                'stock_count' => (int) ($current['stock_count'] ?? 0),
                'reservation_count' => (int) ($current['reservation_count'] ?? 0),
                'ledger_count' => (int) ($current['ledger_count'] ?? 0),
                'quota_count' => (int) ($current['quota_count'] ?? 0),
                'conservation' => (array) ($current['conservation'] ?? []),
                'history_retained' => $this->historyRetained($manifest, $current),
                'mode' => $this->memoryProbe
                    ? $this->memoryMode
                    : (string) ($current['mode'] ?? 'off'),
                'writer_enabled_count' => (int) ($current['writer_enabled_count'] ?? 0),
                'fresh_journal' => $fresh,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @param list<int> $websiteIds
     * @return array<string, mixed>
     */
    public function allowlist(
        ?array $targetDb,
        string $checkpointId,
        array $websiteIds,
    ): array {
        $verified = $this->verify($targetDb, $checkpointId);
        if (empty($verified['ok'])) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => 'mig_p3a_warehouse_verify_required',
                'verify' => $verified,
            ];
        }
        try {
            if ($this->memoryProbe) {
                $this->memoryMode = 'allowlist';
                return [
                    'ok' => true,
                    'phase' => self::PHASE,
                    'checkpoint_id' => $checkpointId,
                    'mode' => 'allowlist',
                    'allowlist' => array_values(array_unique(array_map('intval', $websiteIds))),
                ];
            }
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $guard->assertIsolatedDatabase($db);
            $write = ($this->databaseProbe ?? new WarehouseMigrationDatabaseProbe())
                ->setWriterEnabled($db, $websiteIds, true);
            $checkpoint = $this->checkpoint($guard);
            $checkpoint->appendJournal($checkpointId, 'p3a_warehouse_allowlist_done', [
                'database' => (string) $db['database'],
                'websites' => array_values(array_unique(array_map('intval', $websiteIds))),
                'writer_enabled_updated' => (int) $write['updated'],
                'writer_enabled_count' => (int) $write['enabled_count'],
                'verified_manifest_hash' => (string) $verified['manifest_hash'],
            ]);

            return [
                'ok' => true,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'mode' => 'allowlist',
                'allowlist' => array_values(array_unique(array_map('intval', $websiteIds))),
                'writer_enabled_updated' => (int) $write['updated'],
                'writer_enabled_count' => (int) $write['enabled_count'],
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function rollbackToModeOff(
        ?array $targetDb = null,
        string $checkpointId = '',
    ): array {
        try {
            $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
            $guard = $this->memoryProbe
                ? ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())
                : $this->resolveFingerprintGuard($db);
            $guard->assertIsolatedDatabase($db);
            $checkpointId = $this->resolveCheckpointId($checkpointId);
            $checkpoint = $this->checkpoint($guard);
            if (!$checkpoint->hasCheckpoint($checkpointId)) {
                throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
            }
            $checkpoint->rollbackGuard($checkpointId);
            if ($this->memoryProbe) {
                $this->rolloutWritable = false;
                $this->memoryMode = 'off';
                $writer = ['updated' => 0, 'enabled_count' => 0];
                $snapshot = $this->memoryPreflight();
            } else {
                $writer = ($this->databaseProbe ?? new WarehouseMigrationDatabaseProbe())
                    ->setWriterEnabled($db, [], false);
                $snapshot = $this->buildPreflight(
                    ($this->databaseProbe ?? new WarehouseMigrationDatabaseProbe())
                        ->inspect($db),
                    $guard->fingerprint($db),
                );
            }
            $checkpoint->appendJournal($checkpointId, 'p3a_warehouse_mode_off', [
                'database' => (string) $db['database'],
                'writer_disabled_updated' => (int) $writer['updated'],
                'history_retained' => true,
                'mapping_retained' => true,
                'continue_forward' => true,
            ]);

            return [
                'ok' => true,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'mode' => 'off',
                'writer_enabled_count' => (int) $writer['enabled_count'],
                'history_retained' => true,
                'mapping_retained' => true,
                'stock_count' => (int) ($snapshot['stock_count'] ?? 0),
                'quota_count' => (int) ($snapshot['quota_count'] ?? count($this->memoryWarehouseStocks)),
                'reservation_mapped_count' => count(
                    (array) ($snapshot['_reservation_plans'] ?? $this->memoryMappedReservations),
                ),
                'continue_forward' => true,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => $exception->getMessage(),
                'continue_forward' => true,
            ];
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function stocks(): array
    {
        return $this->memoryStocks;
    }

    /** @return array<string, array<string, mixed>> */
    public function warehouseStocks(): array
    {
        return $this->memoryWarehouseStocks;
    }

    /** @return array<string, array<string, mixed>> */
    public function mappedReservations(): array
    {
        return $this->memoryMappedReservations;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildPreflight(array $snapshot, string $fingerprint): array
    {
        if (empty($snapshot['ok'])) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'fingerprint' => $fingerprint,
                'error' => (string) ($snapshot['error'] ?? 'mig_p3a_warehouse_probe_failed'),
                'conflict_count' => 0,
                'conflicts' => [],
                'apply_ready' => false,
                '_snapshot' => $snapshot,
            ];
        }

        $warehouses = [];
        foreach ((array) $snapshot['warehouses'] as $warehouse) {
            $warehouses[(int) $warehouse['warehouse_id']] = $warehouse;
        }
        $bindings = [];
        $writerEnabledCount = 0;
        foreach ((array) $snapshot['authorizations'] as $authorization) {
            if ((int) ($authorization['writer_enabled'] ?? 0) === 1) {
                $writerEnabledCount++;
            }
            if ((int) $authorization['enabled'] !== 1
                || (int) $authorization['is_default'] !== 1
            ) {
                continue;
            }
            $key = $this->storeKey(
                (int) $authorization['website_id'],
                (int) $authorization['store_id'],
            );
            $bindings[$key][] = $authorization;
        }
        $websiteDefaults = [];
        foreach ($warehouses as $warehouse) {
            if ((int) $warehouse['enabled'] !== 1
                || (int) $warehouse['is_default_logical'] !== 1
                || !$this->isLogicalWarehouse($warehouse)
            ) {
                continue;
            }
            $key = (int) $warehouse['website_id'] . ':' . (string) $warehouse['mode'];
            $websiteDefaults[$key][] = $warehouse;
        }

        $conflicts = [];
        $quotaPlans = [];
        $reservationPlans = [];
        $ledgerPlans = [];
        $conservation = [];
        foreach ((array) $snapshot['stocks'] as $stock) {
            $warehouse = $this->targetWarehouse(
                $stock,
                'stock',
                (string) $stock['stock_id'],
                $warehouses,
                $bindings,
                $websiteDefaults,
                $conflicts,
            );
            if ($warehouse === null) {
                continue;
            }
            try {
                $available = $this->availability->availableMinor(
                    (string) $stock['strategy'],
                    (int) $stock['on_hand_minor'],
                    (int) $stock['reserved_minor'],
                    (int) $stock['oversell_allowance'],
                    (int) $stock['preorder_allowance'],
                );
                if ($available === PHP_INT_MAX) {
                    throw new \RuntimeException('unlimited_strategy_not_representable');
                }
            } catch (\Throwable $exception) {
                $conflicts[] = [
                    'code' => 'stock_availability_unmappable',
                    'stock_id' => (int) $stock['stock_id'],
                    'error' => $exception->getMessage(),
                ];
                continue;
            }
            $key = $this->warehouseOfferKey(
                (int) $stock['website_id'],
                (int) $warehouse['warehouse_id'],
                (int) $stock['offer_id'],
            );
            $quotaPlans[$key] ??= [
                'key' => $key,
                'website_id' => (int) $stock['website_id'],
                'warehouse_id' => (int) $warehouse['warehouse_id'],
                'offer_id' => (int) $stock['offer_id'],
                'qty_minor' => 0,
                'source_stock_ids' => [],
            ];
            $quotaPlans[$key]['qty_minor'] = $this->checkedAdd(
                (int) $quotaPlans[$key]['qty_minor'],
                $available,
            );
            $quotaPlans[$key]['source_stock_ids'][] = (int) $stock['stock_id'];
            $conservation[$key] ??= $this->emptyConservation();
            $conservation[$key]['on_hand_minor'] = $this->checkedAdd(
                $conservation[$key]['on_hand_minor'],
                (int) $stock['on_hand_minor'],
            );
            $conservation[$key]['reserved_minor'] = $this->checkedAdd(
                $conservation[$key]['reserved_minor'],
                (int) $stock['reserved_minor'],
            );
            $conservation[$key]['available_minor'] = $this->checkedAdd(
                $conservation[$key]['available_minor'],
                $available,
            );
        }

        $existingQuotas = [];
        foreach ((array) $snapshot['quotas'] as $quota) {
            $existingQuotas[$this->warehouseOfferKey(
                (int) $quota['website_id'],
                (int) $quota['warehouse_id'],
                (int) $quota['offer_id'],
            )] = $quota;
        }
        foreach ($quotaPlans as $key => $plan) {
            $existing = $existingQuotas[$key] ?? null;
            if ($existing !== null
                && (int) $existing['qty_minor'] !== (int) $plan['qty_minor']
            ) {
                $conflicts[] = [
                    'code' => 'warehouse_quota_conflict',
                    'key' => $key,
                    'expected_qty_minor' => (int) $plan['qty_minor'],
                    'actual_qty_minor' => (int) $existing['qty_minor'],
                ];
            }
        }

        foreach ((array) $snapshot['reservations'] as $reservation) {
            $warehouse = $this->targetWarehouse(
                $reservation,
                'reservation',
                (string) $reservation['reservation_uuid'],
                $warehouses,
                $bindings,
                $websiteDefaults,
                $conflicts,
            );
            if ($warehouse === null) {
                continue;
            }
            $current = (int) ($reservation['warehouse_id'] ?? 0);
            if ($current > 0 && $current !== (int) $warehouse['warehouse_id']) {
                $conflicts[] = [
                    'code' => 'reservation_warehouse_conflict',
                    'reservation_uuid' => (string) $reservation['reservation_uuid'],
                    'expected' => (int) $warehouse['warehouse_id'],
                    'actual' => $current,
                ];
                continue;
            }
            $reservationPlans[] = [
                'reservation_id' => (int) $reservation['reservation_id'],
                'reservation_uuid' => (string) $reservation['reservation_uuid'],
                'website_id' => (int) $reservation['website_id'],
                'store_id' => (int) $reservation['store_id'],
                'offer_id' => (int) $reservation['offer_id'],
                'quantity_minor' => (int) $reservation['quantity_minor'],
                'warehouse_id' => (int) $warehouse['warehouse_id'],
            ];
            $key = $this->warehouseOfferKey(
                (int) $reservation['website_id'],
                (int) $warehouse['warehouse_id'],
                (int) $reservation['offer_id'],
            );
            $conservation[$key] ??= $this->emptyConservation();
            $conservation[$key]['reservation_qty_minor'] = $this->checkedAdd(
                $conservation[$key]['reservation_qty_minor'],
                (int) $reservation['quantity_minor'],
            );
            $conservation[$key]['reservation_count']++;
        }

        foreach ((array) $snapshot['ledgers'] as $ledger) {
            $warehouse = $this->targetWarehouse(
                $ledger,
                'ledger',
                (string) $ledger['event_uuid'],
                $warehouses,
                $bindings,
                $websiteDefaults,
                $conflicts,
            );
            if ($warehouse === null) {
                continue;
            }
            $current = (int) ($ledger['warehouse_id'] ?? 0);
            if ($current > 0 && $current !== (int) $warehouse['warehouse_id']) {
                $conflicts[] = [
                    'code' => 'ledger_warehouse_conflict',
                    'event_uuid' => (string) $ledger['event_uuid'],
                    'expected' => (int) $warehouse['warehouse_id'],
                    'actual' => $current,
                ];
                continue;
            }
            $ledgerPlans[] = [
                'ledger_id' => (int) $ledger['ledger_id'],
                'event_uuid' => (string) $ledger['event_uuid'],
                'website_id' => (int) $ledger['website_id'],
                'store_id' => (int) $ledger['store_id'],
                'offer_id' => (int) $ledger['offer_id'],
                'event_type' => (string) $ledger['event_type'],
                'qty_delta_minor' => (int) $ledger['qty_delta_minor'],
                'warehouse_id' => (int) $warehouse['warehouse_id'],
            ];
            if ((string) $ledger['event_type'] === InventoryLedger::TYPE_COMMIT) {
                $key = $this->warehouseOfferKey(
                    (int) $ledger['website_id'],
                    (int) $warehouse['warehouse_id'],
                    (int) $ledger['offer_id'],
                );
                $conservation[$key] ??= $this->emptyConservation();
                $conservation[$key]['committed_minor'] = $this->checkedAdd(
                    $conservation[$key]['committed_minor'],
                    max(0, -(int) $ledger['qty_delta_minor']),
                );
            }
        }

        ksort($quotaPlans);
        usort(
            $reservationPlans,
            static fn (array $a, array $b): int => $a['reservation_id'] <=> $b['reservation_id'],
        );
        usort(
            $ledgerPlans,
            static fn (array $a, array $b): int => $a['ledger_id'] <=> $b['ledger_id'],
        );
        ksort($conservation);
        $snapshots = (array) $snapshot['snapshots'];
        $rowCounts = [];
        $rowHashes = [];
        $watermarks = [];
        foreach ([
            'stock',
            'reservation_immutable',
            'ledger_immutable',
            'warehouse',
            'authorization_mapping',
            'authorization_state',
            'quota',
        ] as $name) {
            $rowCounts[$name] = (int) ($snapshots[$name]['count'] ?? 0);
            $rowHashes[$name] = (string) ($snapshots[$name]['digest'] ?? '');
            $watermarks[$name] = (int) ($snapshots[$name]['watermark'] ?? 0);
        }
        $rowHashes['quota_plan'] = $this->digest(array_values($quotaPlans));
        $rowHashes['reservation_plan'] = $this->digest($reservationPlans);
        $rowHashes['ledger_plan'] = $this->digest($ledgerPlans);
        $rowHashes['conservation'] = $this->digest($conservation);

        return [
            'ok' => $conflicts === [],
            'phase' => self::PHASE,
            'fingerprint' => $fingerprint,
            'error' => $conflicts === [] ? null : self::ERROR_CONFLICT,
            'stock_count' => count((array) $snapshot['stocks']),
            'reservation_count' => count((array) $snapshot['reservations']),
            'ledger_count' => count((array) $snapshot['ledgers']),
            'quota_count' => count((array) $snapshot['quotas']),
            'quota_plan_count' => count($quotaPlans),
            'reservation_plan_count' => count($reservationPlans),
            'ledger_plan_count' => count($ledgerPlans),
            'conflict_count' => count($conflicts),
            'quarantine_count' => count($conflicts),
            'conflicts' => $conflicts,
            'conservation' => $conservation,
            'schema_fingerprints' => (array) $snapshot['schema_fingerprints'],
            'row_counts' => $rowCounts,
            'row_hashes' => $rowHashes,
            'watermarks' => $watermarks,
            'apply_ready' => $conflicts === [],
            'shared_db_apply_forbidden' => true,
            'normal_test_isolation' => true,
            'mode' => $writerEnabledCount > 0 ? 'allowlist' : 'off',
            'writer_enabled_count' => $writerEnabledCount,
            '_quota_plans' => array_values($quotaPlans),
            '_reservation_plans' => $reservationPlans,
            '_ledger_plans' => $ledgerPlans,
            '_snapshot' => $snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $warehouses
     * @param array<string, list<array<string, mixed>>> $bindings
     * @param array<string, list<array<string, mixed>>> $websiteDefaults
     * @param list<array<string, mixed>> $conflicts
     * @return array<string, mixed>|null
     */
    private function targetWarehouse(
        array $row,
        string $kind,
        string $identity,
        array $warehouses,
        array $bindings,
        array $websiteDefaults,
        array &$conflicts,
    ): ?array {
        $websiteId = (int) $row['website_id'];
        $storeId = (int) $row['store_id'];
        if ($storeId === 0) {
            $candidates = $websiteDefaults[$websiteId . ':' . Warehouse::MODE_NORMAL] ?? [];
            if (count($candidates) !== 1) {
                $conflicts[] = [
                    'code' => count($candidates) === 0
                        ? 'default_warehouse_missing'
                        : 'default_warehouse_ambiguous',
                    'kind' => $kind,
                    'identity' => $identity,
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                ];
                return null;
            }
            return $candidates[0];
        }

        $candidates = $bindings[$this->storeKey($websiteId, $storeId)] ?? [];
        if (count($candidates) !== 1) {
            $conflicts[] = [
                'code' => count($candidates) === 0
                    ? 'default_authorization_missing'
                    : 'default_authorization_ambiguous',
                'kind' => $kind,
                'identity' => $identity,
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ];
            return null;
        }
        $authorization = $candidates[0];
        $warehouse = $warehouses[(int) $authorization['warehouse_id']] ?? null;
        if ($warehouse === null
            || (int) $warehouse['website_id'] !== $websiteId
            || (int) $warehouse['enabled'] !== 1
            || !$this->isLogicalWarehouse($warehouse)
        ) {
            $conflicts[] = [
                'code' => 'authorized_warehouse_invalid',
                'kind' => $kind,
                'identity' => $identity,
            ];
            return null;
        }
        $storeMode = strtolower((string) $authorization['store_mode_snapshot']);
        $expectedMode = match ($storeMode) {
            'dev', 'test' => Warehouse::MODE_TEST,
            'normal' => Warehouse::MODE_NORMAL,
            default => null,
        };
        if ($expectedMode === null) {
            $conflicts[] = [
                'code' => 'store_mode_invalid',
                'kind' => $kind,
                'identity' => $identity,
                'store_mode' => $storeMode,
            ];
            return null;
        }
        if ((string) $warehouse['mode'] !== $expectedMode) {
            $conflicts[] = [
                'code' => self::ERROR_MODE_CROSS,
                'kind' => $kind,
                'identity' => $identity,
                'store_mode' => $storeMode,
                'warehouse_mode' => (string) $warehouse['mode'],
            ];
            return null;
        }

        return $warehouse;
    }

    /**
     * @param array<string, mixed> $preflight
     */
    private function manifest(
        string $checkpointId,
        string $fingerprint,
        array $db,
        array $preflight,
    ): MigrationManifest {
        return MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-apply',
            'repo' => 'WelineFramework',
            'branch' => 'working-tree',
            'commit' => 'current-source',
            'connector_fingerprint' => $fingerprint,
            'schema_fingerprints' => (array) $preflight['schema_fingerprints'],
            'row_counts' => (array) $preflight['row_counts'],
            'row_hashes' => (array) $preflight['row_hashes'],
            'watermarks' => (array) $preflight['watermarks'],
            'backup_ref' => 'clone:' . (string) $db['database'],
            'created_at' => gmdate('c'),
        ]);
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     * @param list<array<string, mixed>> $diffs
     */
    private function compareDatabaseManifest(
        MigrationManifest $manifest,
        array $current,
        array $journal,
        array &$diffs,
    ): void {
        foreach ($manifest->schemaFingerprints as $name => $expected) {
            $actual = (string) ($current['schema_fingerprints'][$name] ?? '');
            if ($actual === '' || !hash_equals((string) $expected, $actual)) {
                $diffs[] = ['code' => 'schema_fingerprint_changed', 'table' => $name];
            }
        }
        foreach ([
            'stock',
            'reservation_immutable',
            'ledger_immutable',
            'warehouse',
            'authorization_mapping',
        ] as $name) {
            if ((int) ($manifest->rowCounts[$name] ?? -1)
                    !== (int) ($current['row_counts'][$name] ?? -2)
            ) {
                $diffs[] = ['code' => $name . '_count_changed'];
            }
            if (!hash_equals(
                (string) ($manifest->rowHashes[$name] ?? ''),
                (string) ($current['row_hashes'][$name] ?? ''),
            )) {
                $diffs[] = ['code' => $name . '_digest_changed'];
            }
        }
        foreach (['quota_plan', 'reservation_plan', 'ledger_plan', 'conservation'] as $name) {
            if (!hash_equals(
                (string) ($manifest->rowHashes[$name] ?? ''),
                (string) ($current['row_hashes'][$name] ?? ''),
            )) {
                $diffs[] = ['code' => $name . '_changed'];
            }
        }
        if ((int) ($current['conflict_count'] ?? 0) !== 0) {
            $diffs[] = [
                'code' => self::ERROR_CONFLICT,
                'conflicts' => array_slice((array) ($current['conflicts'] ?? []), 0, 20),
            ];
        }
        $applied = null;
        foreach ($journal as $entry) {
            if (($entry['event'] ?? '') === 'p3a_warehouse_apply_done') {
                $applied = (array) ($entry['detail'] ?? []);
            }
        }
        if ($applied === null) {
            $diffs[] = ['code' => 'p3a_warehouse_apply_journal_missing'];
            return;
        }
        $expectedQuotaCount = (int) ($manifest->rowCounts['quota'] ?? 0)
            + (int) ($applied['quota_mapped'] ?? 0);
        if ($expectedQuotaCount !== (int) ($current['quota_count'] ?? -1)) {
            $diffs[] = [
                'code' => 'quota_mapped_count_mismatch',
                'expected' => $expectedQuotaCount,
                'actual' => (int) ($current['quota_count'] ?? -1),
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $diffs
     */
    private function compareMemoryManifest(
        MigrationManifest $manifest,
        array $current,
        array &$diffs,
    ): void {
        foreach (['stock_count', 'reservation_count'] as $field) {
            $name = str_replace('_count', '', $field);
            if ((int) ($manifest->rowCounts[$name] ?? -1)
                    !== (int) ($current[$field] ?? -2)
            ) {
                $diffs[] = ['code' => $field . '_changed'];
            }
        }
        if ((int) ($current['diff_count'] ?? 0) !== 0) {
            $diffs[] = ['code' => 'memory_conservation_failed'];
        }
    }

    /**
     * @param array<string, mixed> $db
     * @return array<string, mixed>
     */
    private function applyMemory(
        array $db,
        DatabaseFingerprintGuard $guard,
        string $fingerprint,
    ): array {
        $checkpointId = $this->newCheckpointId();
        $preflight = $this->memoryPreflight();
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-apply',
            'repo' => 'WelineFramework',
            'branch' => 'test',
            'commit' => 'memory-pure-map',
            'connector_fingerprint' => $fingerprint,
            'schema_fingerprints' => ['memory' => hash('sha256', 'memory')],
            'row_counts' => [
                'stock' => (int) $preflight['stock_count'],
                'reservation' => (int) $preflight['reservation_count'],
            ],
            'row_hashes' => ['conservation' => $this->digest($preflight['offer_totals'])],
            'watermarks' => ['memory' => count($this->memoryMapping)],
            'backup_ref' => 'clone:' . (string) $db['database'],
            'created_at' => gmdate('c'),
        ]);
        $checkpoint = $this->checkpoint($guard);
        $checkpoint->checkpoint($manifest);
        $checkpoint->applyGuard($db, $checkpointId, $manifest);
        $mapped = 0;
        $already = 0;
        foreach ($this->memoryStocks as $stock) {
            $plan = $this->mapStock($stock);
            $key = (string) $plan['warehouse_stock']['legacy_stock_key'];
            if (isset($this->memoryMapping[$key])) {
                $already++;
                continue;
            }
            $this->memoryWarehouseStocks[$key] = $plan['warehouse_stock'];
            $this->memoryMapping[$key] = (int) $plan['warehouse_id'];
            $mapped++;
        }
        $reservationMapped = 0;
        $reservationAlready = 0;
        foreach ($this->memoryReservations as $uuid => $reservation) {
            $plan = $this->mapReservation($reservation);
            if (isset($this->memoryMappedReservations[$uuid])) {
                $reservationAlready++;
                continue;
            }
            $this->memoryMappedReservations[$uuid] = $plan['row'];
            $this->memoryReservations[$uuid]['warehouse_id'] = $plan['warehouse_id'];
            $reservationMapped++;
        }
        $checkpoint->appendJournal($checkpointId, 'p3a_warehouse_apply_done', [
            'quota_mapped' => $mapped,
            'reservation_mapped' => $reservationMapped,
            'ledger_mapped' => 0,
            'history_retained' => true,
        ]);
        $this->lastCheckpointId = $checkpointId;
        $this->lastTargetDb = $db;

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => (string) $db['database'],
            'fingerprint' => $fingerprint,
            'mapped' => $mapped,
            'already' => $already,
            'reservations_mapped' => $reservationMapped,
            'reservations_already' => $reservationAlready,
            'warehouse_stock_count' => count($this->memoryWarehouseStocks),
            'mode' => $this->memoryMode,
            'history_retained' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryPreflight(): array
    {
        $totals = [];
        $plans = [];
        foreach ($this->memoryStocks as $stock) {
            $plan = $this->mapStock($stock);
            $plans[] = $plan;
            $key = $this->stockKey(
                (int) $stock['website_id'],
                (int) $stock['store_id'],
                (int) $stock['offer_id'],
            );
            $totals[$key] = [
                'on_hand_minor' => (int) $stock['on_hand_minor'],
                'reserved_minor' => (int) $stock['reserved_minor'],
                'deducted_minor' => (int) ($stock['deducted_minor'] ?? 0),
                'available_minor' => (int) $stock['available_minor'],
            ];
        }
        $mappedTotals = [];
        foreach ($this->memoryWarehouseStocks as $key => $stock) {
            $mappedTotals[$key] = [
                'on_hand_minor' => (int) $stock['on_hand_minor'],
                'reserved_minor' => (int) $stock['reserved_minor'],
                'deducted_minor' => (int) $stock['deducted_minor'],
                'available_minor' => (int) $stock['available_minor'],
            ];
        }
        $diffs = [];
        if ($this->memoryMapping !== [] && $totals !== $mappedTotals) {
            $diffs[] = ['code' => 'memory_offer_conservation_mismatch'];
        }

        return [
            'ok' => $diffs === [],
            'phase' => self::PHASE,
            'stock_count' => count($this->memoryStocks),
            'reservation_count' => count($this->memoryReservations),
            'ledger_count' => 0,
            'quota_count' => count($this->memoryWarehouseStocks),
            'already_mapped' => count($this->memoryMapping),
            'plans' => $plans,
            'reservation_plans' => array_map(
                fn (array $row): array => $this->mapReservation($row),
                $this->memoryReservations,
            ),
            'offer_totals' => $totals,
            'offer_totals_legacy' => $totals,
            'offer_totals_mapped' => $mappedTotals,
            'conservation' => $totals,
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $this->memoryMode,
            'shared_db_apply_forbidden' => true,
            'normal_test_isolation' => true,
            'history_retained' => true,
        ];
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
        foreach ($clones->list() as $handle) {
            if ($handle->database === $database) {
                return $clones->guardedFingerprint();
            }
        }
        throw new \RuntimeException(self::ERROR_CLONE_NOT_REGISTERED . ':' . $database);
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        $database = trim((string) ($targetDb['database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_*'
                . ' (create via php bin/w mig:foundation clone-create --mode=schema --purpose=p3awarehouse)',
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
        ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())
            ->assertIsolatedDatabase($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function publicPreflight(array $preflight): array
    {
        unset(
            $preflight['_quota_plans'],
            $preflight['_reservation_plans'],
            $preflight['_ledger_plans'],
            $preflight['_snapshot'],
        );
        $preflight['conflicts'] = array_slice((array) ($preflight['conflicts'] ?? []), 0, 100);
        $preflight['conflicts_truncated'] =
            (int) ($preflight['conflict_count'] ?? 0) > count($preflight['conflicts']);

        return $preflight;
    }

    private function historyRetained(MigrationManifest $manifest, array $current): bool
    {
        if ($this->memoryProbe) {
            return (int) ($manifest->rowCounts['stock'] ?? -1)
                    === (int) ($current['stock_count'] ?? -2)
                && (int) ($manifest->rowCounts['reservation'] ?? -1)
                    === (int) ($current['reservation_count'] ?? -2);
        }
        foreach (['stock', 'reservation_immutable', 'ledger_immutable'] as $name) {
            if ((int) ($manifest->rowCounts[$name] ?? -1)
                    !== (int) ($current['row_counts'][$name] ?? -2)
                || !hash_equals(
                    (string) ($manifest->rowHashes[$name] ?? ''),
                    (string) ($current['row_hashes'][$name] ?? ''),
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> */
    private function emptyConservation(): array
    {
        return [
            'on_hand_minor' => 0,
            'reserved_minor' => 0,
            'committed_minor' => 0,
            'available_minor' => 0,
            'reservation_qty_minor' => 0,
            'reservation_count' => 0,
        ];
    }

    /** @param array<string, mixed> $warehouse */
    private function isLogicalWarehouse(array $warehouse): bool
    {
        return (string) ($warehouse['warehouse_type'] ?? '') === Warehouse::TYPE_LOGICAL
            || (int) ($warehouse['is_default_logical'] ?? 0) === 1;
    }

    private function newCheckpointId(): string
    {
        return 'p3awh-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    private function stockKey(int $websiteId, int $storeId, int $offerId): string
    {
        return $websiteId . ':' . $storeId . ':' . $offerId;
    }

    private function storeKey(int $websiteId, int $storeId): string
    {
        return $websiteId . ':' . $storeId;
    }

    private function warehouseOfferKey(
        int $websiteId,
        int $warehouseId,
        int $offerId,
    ): string {
        return $websiteId . ':' . $warehouseId . ':' . $offerId;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \RuntimeException('mig_p3a_warehouse_quantity_overflow');
        }
        if ($right < 0 && $left < PHP_INT_MIN - $right) {
            throw new \RuntimeException('mig_p3a_warehouse_quantity_underflow');
        }

        return $left + $right;
    }

    private function digest(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
