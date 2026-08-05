<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * Clone-bound CustomerAsset conservation verification and tender cutover.
 *
 * No account, reservation or ledger fact is rewritten by this service. Apply
 * creates a checkpoint and enters shadow; an independent fresh verification
 * is required before one exact Website may enter allowlist mode.
 */
final class CustomerAssetMigrationService
{
    public const PHASE = 'p4d-customer-asset';
    public const CAPABILITY = CustomerAssetService::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p4d_customer_asset_requires_isolated_database';
    public const ERROR_POSTGRESQL = 'mig_p4d_customer_asset_postgresql_required';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p4d_customer_asset_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p4d_customer_asset_full_clone_required';
    public const ERROR_SCOPE = 'mig_p4d_customer_asset_website_required';
    public const ERROR_NO_SAMPLE = 'mig_p4d_customer_asset_durable_sample_required';
    public const ERROR_INTEGRITY = 'mig_p4d_customer_asset_conservation_failed';
    public const ERROR_SHADOW_DIFF = 'mig_p4d_customer_asset_shadow_diff';
    public const ERROR_CHECKPOINT = 'mig_p4d_customer_asset_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p4d_customer_asset_checkpoint_fingerprint_mismatch';
    public const ERROR_VERIFY = 'mig_p4d_customer_asset_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p4d_customer_asset_allowlist_scope_mismatch';
    public const ERROR_ROLLOUT_MODE = 'mig_p4d_customer_asset_rollout_mode_invalid';

    /** @var array<string,list<string>> */
    private const FACT_FIELDS = [
        'accounts' => [
            'account_id',
            'customer_id',
            'website_id',
            'asset_code',
            'namespace',
            'available_minor',
            'reserved_minor',
            'version',
            'cas_token',
        ],
        'ledger' => [
            'entry_id',
            'event_id',
            'account_id',
            'customer_id',
            'website_id',
            'asset_code',
            'namespace',
            'event_type',
            'amount_minor',
            'reservation_id',
            'request_hash',
            'balance_after_available',
            'balance_after_reserved',
            'account_version',
            'meta_json',
        ],
        'reservations' => [
            'reservation_id',
            'account_id',
            'customer_id',
            'website_id',
            'asset_code',
            'namespace',
            'reserve_event_id',
            'reserve_request_hash',
            'amount_minor',
            'returned_amount_minor',
            'status',
            'version',
            'cas_token',
            'terminal_event_id',
            'terminal_request_hash',
        ],
    ];

    /** @var array<string,list<string>> */
    private const INT_FIELDS = [
        'accounts' => [
            'website_id',
            'available_minor',
            'reserved_minor',
            'version',
        ],
        'ledger' => [
            'website_id',
            'amount_minor',
            'balance_after_available',
            'balance_after_reserved',
            'account_version',
        ],
        'reservations' => [
            'website_id',
            'amount_minor',
            'returned_amount_minor',
            'version',
        ],
    ];

    /** @var array<string,class-string<Model>> */
    private const FACT_MODELS = [
        'accounts' => AssetAccount::class,
        'ledger' => AssetLedger::class,
        'reservations' => AssetReservation::class,
    ];

    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;
    private ?MigrationTargetBinder $targetBinder = null;
    private ?CustomerAssetRolloutGate $rolloutGate = null;
    private bool $memoryTarget = false;
    private bool $forceShadowMismatch = false;

    /** @var array<string,list<array<string,mixed>>> */
    private array $memoryRows = [
        'accounts' => [],
        'ledger' => [],
        'reservations' => [],
    ];

    public function __construct()
    {
    }

    public static function forTesting(?string $journalDir = null): self
    {
        $guard = new DatabaseFingerprintGuard();
        $service = new self();
        $service->memoryTarget = true;
        $service->fingerprintGuard = $guard;
        $service->checkpointService = new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(
                $journalDir ?? sys_get_temp_dir() . '/mig_p4dasset_' . uniqid('', true),
            ),
        );
        $service->rolloutGate = CustomerAssetRolloutGate::forTestingConfiguration();

        return $service;
    }

    public function rollout(): CustomerAssetRolloutGate
    {
        return $this->runtimeRollout();
    }

    /**
     * Read-only integrity diagnostic for an already-bound runtime database.
     *
     * Unlike migration preflight this method never binds a target, writes a
     * checkpoint, or changes rollout state. Backend diagnostics reuse the
     * migration's canonical ledger replay and reservation invariant checks.
     *
     * @return array<string,mixed>
     */
    public function diagnoseIntegrity(int $websiteId): array
    {
        $this->assertScope($websiteId);
        $snapshot = $this->snapshot($websiteId);

        return $this->publicSnapshot($snapshot) + [
            'website_id' => $websiteId,
            'mode' => $this->runtimeRollout()->mode(self::CAPABILITY),
            'read_only' => true,
        ];
    }

    /**
     * Explicit memory-only seam for invariant-focused unit tests.
     *
     * @param list<array<string,mixed>> $accounts
     * @param list<array<string,mixed>> $ledger
     * @param list<array<string,mixed>> $reservations
     */
    public function seedFacts(
        array $accounts,
        array $ledger,
        array $reservations,
    ): void {
        $this->assertMemoryTarget();
        $this->memoryRows = [
            'accounts' => $accounts,
            'ledger' => $ledger,
            'reservations' => $reservations,
        ];
    }

    public function forceShadowMismatchForTesting(): void
    {
        $this->assertMemoryTarget();
        $this->forceShadowMismatch = true;
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function preflight(?array $targetDb, int $websiteId): array
    {
        $this->assertScope($websiteId);
        $target = $this->prepareTarget($targetDb);
        $snapshot = $this->snapshot($websiteId);

        return $this->publicSnapshot($snapshot) + [
            'phase' => self::PHASE,
            'database' => $target['database'],
            'database_type' => 'pgsql',
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'mode' => $this->runtimeRollout()->mode(self::CAPABILITY),
            'shared_db_apply_forbidden' => true,
            'full_clone_required' => true,
            'business_writes' => 0,
            'apply_ready' => $snapshot['apply_ready'],
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function apply(?array $targetDb, int $websiteId): array
    {
        $this->assertScope($websiteId);
        $target = $this->prepareTarget($targetDb);
        $snapshot = $this->snapshot($websiteId);
        if (!$snapshot['apply_ready']) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'fingerprint' => $target['fingerprint'],
                'error' => $snapshot['sample_count'] === 0
                    ? self::ERROR_NO_SAMPLE
                    : self::ERROR_INTEGRITY,
            ] + $this->publicSnapshot($snapshot);
        }

        $rollout = $this->runtimeRollout();
        $modeBefore = $rollout->mode(self::CAPABILITY);
        if (!in_array($modeBefore, [
            CommerceRolloutGateInterface::MODE_OFF,
            CommerceRolloutGateInterface::MODE_SHADOW,
        ], true)) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'error' => self::ERROR_ROLLOUT_MODE,
                'mode' => $modeBefore,
            ];
        }

        $checkpointId = 'p4dasset-' . gmdate('YmdHis') . '-'
            . substr(bin2hex(random_bytes(3)), 0, 6);
        $manifest = $this->manifest($checkpointId, $target, $snapshot);
        $checkpoint = $this->checkpoint($target['guard']);
        // Immutable business facts are frozen before the first rollout write.
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p4d_asset_preflight_snapshot', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'mode_before' => $modeBefore,
            'row_counts' => $snapshot['facts']['row_counts'],
            'fact_hash' => $snapshot['facts']['row_hashes']['combined'],
            'obligation_hash' => $snapshot['facts']['row_hashes']['obligations'],
            'business_writes' => 0,
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $rollout->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_SHADOW,
        );
        $configuration = $rollout->configuration();
        if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4d_customer_asset_shadow_readback_failed');
        }
        if ($this->forceShadowMismatch) {
            $rollout->setMode(
                self::CAPABILITY,
                CommerceRolloutGateInterface::MODE_OFF,
            );
            $checkpoint->appendJournal($checkpointId, 'p4d_asset_shadow_rejected', [
                'database' => $target['database'],
                'error' => self::ERROR_SHADOW_DIFF,
                'mode' => CommerceRolloutGateInterface::MODE_OFF,
                'business_writes' => 0,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => $target['database'],
                'fingerprint' => $target['fingerprint'],
                'error' => self::ERROR_SHADOW_DIFF,
                'mode' => CommerceRolloutGateInterface::MODE_OFF,
                'business_writes' => 0,
            ];
        }

        $after = $this->snapshot($websiteId);
        if (!$after['integrity_ok']
            || !$this->sameFacts($snapshot['facts'], $after['facts'])
        ) {
            $rollout->setMode(
                self::CAPABILITY,
                CommerceRolloutGateInterface::MODE_OFF,
            );
            throw new \RuntimeException('mig_p4d_customer_asset_shadow_changed_business_facts');
        }
        $checkpoint->appendJournal($checkpointId, 'p4d_asset_shadow_applied', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'obligation_hash' => $after['facts']['row_hashes']['obligations'],
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'business_writes' => 0,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'database_type' => 'pgsql',
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'fresh_verify_required' => true,
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'obligation_hash' => $after['facts']['row_hashes']['obligations'],
            'row_counts' => $after['facts']['row_counts'],
            'sample_count' => $after['sample_count'],
            'business_writes' => 0,
            'tender_allowlisted' => false,
            'production_on' => false,
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function verify(?array $targetDb, string $checkpointId): array
    {
        $target = $this->prepareTarget($targetDb);
        $checkpointId = $this->requireCheckpointId($checkpointId);
        $checkpoint = $this->checkpoint($target['guard']);
        $fresh = $checkpoint->verifyFresh($checkpointId);
        $stored = $checkpoint->store()?->load($checkpointId);
        if (empty($fresh['ok']) || $stored === null) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'database' => $target['database'],
                'error' => (string) ($fresh['error'] ?? 'migration_checkpoint_missing'),
                'fresh_journal' => $fresh,
            ];
        }

        $manifest = MigrationManifest::fromArray($stored['manifest']);
        $diffs = [];
        if ($manifest->phase !== self::PHASE . '-shadow') {
            $diffs[] = ['code' => 'mig_p4d_customer_asset_checkpoint_phase_mismatch'];
        }
        if (!hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            $diffs[] = ['code' => self::ERROR_FINGERPRINT];
        }
        $websiteId = (int) ($manifest->watermarks['website_id'] ?? -1);
        try {
            $this->assertScope($websiteId);
        } catch (\Throwable) {
            $diffs[] = ['code' => self::ERROR_SCOPE];
        }

        $snapshot = $this->snapshot($websiteId);
        $facts = $snapshot['facts'];
        foreach ($manifest->schemaFingerprints as $name => $expected) {
            $actual = (string) ($facts['schema_fingerprints'][$name] ?? '');
            if ($actual === '' || !hash_equals((string) $expected, $actual)) {
                $diffs[] = ['code' => 'schema_fingerprint_changed', 'fact' => $name];
            }
        }
        foreach ($manifest->rowCounts as $name => $expected) {
            if ((int) $expected !== (int) ($facts['row_counts'][$name] ?? -1)) {
                $diffs[] = ['code' => $name . '_count_changed'];
            }
        }
        foreach ($manifest->rowHashes as $name => $expected) {
            $actual = (string) ($facts['row_hashes'][$name] ?? '');
            if ($actual === '' || !hash_equals((string) $expected, $actual)) {
                $diffs[] = ['code' => $name . '_hash_changed'];
            }
        }
        if (!$snapshot['integrity_ok']) {
            $diffs[] = ['code' => self::ERROR_INTEGRITY];
        }
        if ($this->lastEventDetail(
            $stored['journal'],
            'p4d_asset_shadow_applied',
        ) === null) {
            $diffs[] = ['code' => 'mig_p4d_customer_asset_apply_journal_missing'];
        }

        $configuration = $this->runtimeRollout()->configuration();
        if (!in_array($configuration['mode'], [
            CommerceRolloutGateInterface::MODE_SHADOW,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
        ], true)) {
            $diffs[] = ['code' => self::ERROR_ROLLOUT_MODE];
        }
        if ($configuration['mode'] === CommerceRolloutGateInterface::MODE_ALLOWLIST
            && array_keys($configuration['allowlist']) !== [
                CustomerAssetRolloutGate::scopeKey($websiteId),
            ]
        ) {
            $diffs[] = ['code' => self::ERROR_SCOPE_MISMATCH];
        }

        return [
            'ok' => $diffs === [],
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'database_type' => 'pgsql',
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $configuration['mode'],
            'allowlist' => $configuration['allowlist_rows'],
            'fact_hash' => $facts['row_hashes']['combined'],
            'obligation_hash' => $facts['row_hashes']['obligations'],
            'row_counts' => $facts['row_counts'],
            'sample_count' => $snapshot['sample_count'],
            'fresh_journal' => $fresh,
            'business_writes' => 0,
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function allowlist(
        ?array $targetDb,
        string $checkpointId,
        int $websiteId,
    ): array {
        $this->assertScope($websiteId);
        $verified = $this->verify($targetDb, $checkpointId);
        if (empty($verified['ok'])) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_VERIFY,
                'verify' => $verified,
            ];
        }
        if ((int) $verified['website_id'] !== $websiteId) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_SCOPE_MISMATCH,
                'expected_website_id' => $verified['website_id'],
                'requested_website_id' => $websiteId,
            ];
        }

        $target = $this->prepareTarget($targetDb);
        $rollout = $this->runtimeRollout();
        $subject = CustomerAssetRolloutGate::scopeKey($websiteId);
        $before = $rollout->configuration();
        $rollout->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            [$subject],
        );
        $readback = $rollout->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_ALLOWLIST
            || array_keys($readback['allowlist']) !== [$subject]
        ) {
            throw new \RuntimeException('mig_p4d_customer_asset_allowlist_readback_failed');
        }
        $this->checkpoint($target['guard'])->appendJournal(
            $checkpointId,
            'p4d_asset_allowlist_applied',
            [
                'database' => $target['database'],
                'website_id' => $websiteId,
                'subject' => $subject,
                'verified_manifest_hash' => $verified['manifest_hash'],
                'replayed' => $before['mode'] === CommerceRolloutGateInterface::MODE_ALLOWLIST
                    && array_keys($before['allowlist']) === [$subject],
            ],
        );

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => $readback['mode'],
            'allowlist' => $readback['allowlist_rows'],
            'fresh_verify' => true,
            'tender_allowlisted' => true,
            'production_on' => false,
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function rollbackToModeOff(
        ?array $targetDb,
        string $checkpointId,
    ): array {
        $target = $this->prepareTarget($targetDb);
        $checkpointId = $this->requireCheckpointId($checkpointId);
        $checkpoint = $this->checkpoint($target['guard']);
        $fresh = $checkpoint->verifyFresh($checkpointId);
        $stored = $checkpoint->store()?->load($checkpointId);
        if (empty($fresh['ok']) || $stored === null) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_VERIFY,
                'fresh_journal' => $fresh,
            ];
        }
        $manifest = MigrationManifest::fromArray($stored['manifest']);
        if ($manifest->phase !== self::PHASE . '-shadow'
            || !hash_equals($manifest->connectorFingerprint, $target['fingerprint'])
        ) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }
        $websiteId = (int) ($manifest->watermarks['website_id'] ?? -1);
        $this->assertScope($websiteId);

        $before = $this->snapshot($websiteId);
        if (!$before['integrity_ok']) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_INTEGRITY,
                'diffs' => $before['diffs'],
            ];
        }
        $configurationBefore = $this->runtimeRollout()->configuration();
        $checkpoint->rollbackGuard($checkpointId);
        $this->runtimeRollout()->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );
        $readback = $this->runtimeRollout()->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $readback['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4d_customer_asset_rollback_readback_failed');
        }

        // In-flight settlement/return may converge while new tender is closed.
        // Accept only append/forward progress: no account, ledger or reservation
        // identity that existed before mode-off may disappear.
        $after = $this->snapshot($websiteId);
        if (!$after['integrity_ok']
            || !$this->retainsFacts($before['facts'], $after['facts'])
        ) {
            throw new \RuntimeException('mig_p4d_customer_asset_rollback_lost_obligation');
        }
        $checkpoint->appendJournal($checkpointId, 'p4d_asset_mode_off', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'obligation_hash' => $after['facts']['row_hashes']['obligations'],
            'row_counts' => $after['facts']['row_counts'],
            'ledger_retained' => true,
            'obligations_retained' => true,
            'replayed' => $configurationBefore['mode'] === CommerceRolloutGateInterface::MODE_OFF
                && $configurationBefore['allowlist'] === [],
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'obligation_hash' => $after['facts']['row_hashes']['obligations'],
            'row_counts' => $after['facts']['row_counts'],
            'ledger_retained' => true,
            'obligations_retained' => true,
            'existing_settlement_continues' => true,
            'new_tender_closed' => true,
            'checkpoint_retained' => true,
        ];
    }

    /**
     * @return array{
     *   sample_count:int,
     *   integrity_ok:bool,
     *   apply_ready:bool,
     *   diffs:list<array<string,mixed>>,
     *   facts:array<string,mixed>
     * }
     */
    private function snapshot(int $websiteId): array
    {
        $rows = $this->scopedRows($websiteId);
        $diffs = $this->integrityDiffs($rows, $websiteId);
        $facts = $this->facts($rows);
        $sampleCount = count($rows['accounts']) + count($rows['ledger']);

        return [
            'sample_count' => $sampleCount,
            'integrity_ok' => $diffs === [],
            'apply_ready' => $diffs === []
                && $rows['accounts'] !== []
                && $rows['ledger'] !== [],
            'diffs' => $diffs,
            'facts' => $facts,
        ];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function scopedRows(int $websiteId): array
    {
        $rows = $this->memoryTarget
            ? $this->memoryRows
            : $this->loadOrmRows($websiteId);
        foreach ($rows as $name => $factRows) {
            $rows[$name] = array_values(array_filter(
                $factRows,
                static fn (array $row): bool =>
                    (int) ($row['website_id'] ?? -1) === $websiteId,
            ));
            $rows[$name] = $this->normalizeRows($name, $rows[$name]);
        }
        usort(
            $rows['accounts'],
            static fn (array $left, array $right): int =>
                strcmp((string) $left['account_id'], (string) $right['account_id']),
        );
        usort(
            $rows['ledger'],
            static fn (array $left, array $right): int =>
                [(string) $left['account_id'], (int) $left['account_version'], (string) $left['event_id']]
                <=> [(string) $right['account_id'], (int) $right['account_version'], (string) $right['event_id']],
        );
        usort(
            $rows['reservations'],
            static fn (array $left, array $right): int =>
                strcmp(
                    (string) $left['reservation_id'],
                    (string) $right['reservation_id'],
                ),
        );

        return $rows;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function loadOrmRows(int $websiteId): array
    {
        $rows = [];
        foreach (self::FACT_MODELS as $name => $modelClass) {
            /** @var Model $model */
            $model = ObjectManager::create($modelClass, [], false);
            $rows[$name] = $model->clear()
                ->where('website_id', $websiteId)
                ->select()
                ->fetchArray();
        }

        return $rows;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return list<array<string,mixed>>
     */
    private function integrityDiffs(array $rows, int $websiteId): array
    {
        $diffs = [];
        $accounts = [];
        foreach ($rows['accounts'] as $row) {
            $accountId = trim((string) $row['account_id']);
            if ($accountId === '' || isset($accounts[$accountId])) {
                $diffs[] = ['code' => 'account_id_missing_or_duplicate', 'account_id' => $accountId];
                continue;
            }
            $accounts[$accountId] = $row;
            if ((int) $row['website_id'] !== $websiteId
                || trim((string) $row['customer_id']) === ''
                || trim((string) $row['asset_code']) === ''
                || !in_array($row['namespace'], [AssetAccount::NS_LIVE, AssetAccount::NS_SANDBOX], true)
            ) {
                $diffs[] = ['code' => 'account_identity_invalid', 'account_id' => $accountId];
            }
            if ((int) $row['available_minor'] < 0
                || (int) $row['reserved_minor'] < 0
                || (int) $row['reserved_minor'] > (int) $row['available_minor']
                || (int) $row['version'] < 1
                || !$this->isHash((string) $row['cas_token'])
            ) {
                $diffs[] = ['code' => 'account_balance_invalid', 'account_id' => $accountId];
            }
        }

        $ledgerByAccount = [];
        $ledgerByEvent = [];
        $ledgerByEntry = [];
        $ledgerByReservation = [];
        foreach ($rows['ledger'] as $row) {
            $eventId = trim((string) $row['event_id']);
            $entryId = trim((string) $row['entry_id']);
            $accountId = trim((string) $row['account_id']);
            if ($eventId === '' || isset($ledgerByEvent[$eventId])) {
                $diffs[] = ['code' => 'ledger_event_missing_or_duplicate', 'event_id' => $eventId];
            } else {
                $ledgerByEvent[$eventId] = $row;
            }
            if ($entryId === '' || isset($ledgerByEntry[$entryId])) {
                $diffs[] = ['code' => 'ledger_entry_missing_or_duplicate', 'entry_id' => $entryId];
            } else {
                $ledgerByEntry[$entryId] = $row;
            }
            $account = $accounts[$accountId] ?? null;
            if ($account === null) {
                $diffs[] = ['code' => 'ledger_account_missing', 'event_id' => $eventId];
            } elseif (!$this->sameIdentity($account, $row)) {
                $diffs[] = ['code' => 'ledger_identity_mismatch', 'event_id' => $eventId];
            }
            if (!in_array((string) $row['event_type'], [
                AssetLedger::TYPE_CREDIT,
                AssetLedger::TYPE_RESERVE,
                AssetLedger::TYPE_RELEASE,
                AssetLedger::TYPE_COMMIT,
                AssetLedger::TYPE_RETURN,
            ], true)
                || (int) $row['amount_minor'] <= 0
                || (int) $row['account_version'] < 1
                || (int) $row['balance_after_available'] < 0
                || (int) $row['balance_after_reserved'] < 0
                || (int) $row['balance_after_reserved'] > (int) $row['balance_after_available']
                || !$this->isHash((string) $row['request_hash'])
                || !is_array(json_decode((string) $row['meta_json'], true))
            ) {
                $diffs[] = ['code' => 'ledger_fact_invalid', 'event_id' => $eventId];
            }
            $reservationId = trim((string) ($row['reservation_id'] ?? ''));
            if ($row['event_type'] === AssetLedger::TYPE_CREDIT && $reservationId !== '') {
                $diffs[] = ['code' => 'credit_reservation_forbidden', 'event_id' => $eventId];
            }
            if ($row['event_type'] !== AssetLedger::TYPE_CREDIT && $reservationId === '') {
                $diffs[] = ['code' => 'ledger_reservation_required', 'event_id' => $eventId];
            }
            if ($reservationId !== '') {
                $ledgerByReservation[$reservationId][] = $row;
            }
            $ledgerByAccount[$accountId][] = $row;
        }

        foreach ($accounts as $accountId => $account) {
            $available = 0;
            $reserved = 0;
            $expectedVersion = 1;
            $accountLedger = $ledgerByAccount[$accountId] ?? [];
            if ($accountLedger === []) {
                $diffs[] = ['code' => 'account_ledger_missing', 'account_id' => $accountId];
                continue;
            }
            foreach ($accountLedger as $entry) {
                $eventId = (string) $entry['event_id'];
                $amount = (int) $entry['amount_minor'];
                if ((int) $entry['account_version'] !== $expectedVersion) {
                    $diffs[] = [
                        'code' => 'ledger_version_gap',
                        'event_id' => $eventId,
                        'expected' => $expectedVersion,
                        'actual' => (int) $entry['account_version'],
                    ];
                }
                $validTransition = true;
                switch ((string) $entry['event_type']) {
                    case AssetLedger::TYPE_CREDIT:
                        $available += $amount;
                        break;
                    case AssetLedger::TYPE_RESERVE:
                        if ($amount > $available - $reserved) {
                            $validTransition = false;
                        } else {
                            $reserved += $amount;
                        }
                        break;
                    case AssetLedger::TYPE_RELEASE:
                        if ($amount > $reserved) {
                            $validTransition = false;
                        } else {
                            $reserved -= $amount;
                        }
                        break;
                    case AssetLedger::TYPE_COMMIT:
                        if ($amount > $reserved || $amount > $available) {
                            $validTransition = false;
                        } else {
                            $available -= $amount;
                            $reserved -= $amount;
                        }
                        break;
                    case AssetLedger::TYPE_RETURN:
                        $available += $amount;
                        break;
                    default:
                        $validTransition = false;
                }
                if (!$validTransition) {
                    $diffs[] = ['code' => 'ledger_transition_invalid', 'event_id' => $eventId];
                }
                if ($validTransition
                    && ($available !== (int) $entry['balance_after_available']
                        || $reserved !== (int) $entry['balance_after_reserved'])
                ) {
                    $diffs[] = ['code' => 'ledger_balance_replay_mismatch', 'event_id' => $eventId];
                }
                $expectedVersion++;
            }
            if ($available !== (int) $account['available_minor']
                || $reserved !== (int) $account['reserved_minor']
                || $expectedVersion - 1 !== (int) $account['version']
            ) {
                $diffs[] = ['code' => 'account_terminal_balance_mismatch', 'account_id' => $accountId];
            }
        }

        $reservations = [];
        $activeReservedByAccount = [];
        foreach ($rows['reservations'] as $reservation) {
            $reservationId = trim((string) $reservation['reservation_id']);
            if ($reservationId === '' || isset($reservations[$reservationId])) {
                $diffs[] = [
                    'code' => 'reservation_id_missing_or_duplicate',
                    'reservation_id' => $reservationId,
                ];
                continue;
            }
            $reservations[$reservationId] = $reservation;
            $accountId = trim((string) $reservation['account_id']);
            $account = $accounts[$accountId] ?? null;
            if ($account === null) {
                $diffs[] = ['code' => 'reservation_account_missing', 'reservation_id' => $reservationId];
            } elseif (!$this->sameIdentity($account, $reservation)) {
                $diffs[] = ['code' => 'reservation_identity_mismatch', 'reservation_id' => $reservationId];
            }
            $amount = (int) $reservation['amount_minor'];
            $returned = (int) $reservation['returned_amount_minor'];
            if ($amount <= 0
                || $returned < 0
                || $returned > $amount
                || (int) $reservation['version'] < 1
                || !$this->isHash((string) $reservation['reserve_request_hash'])
                || !$this->isHash((string) $reservation['cas_token'])
            ) {
                $diffs[] = ['code' => 'reservation_fact_invalid', 'reservation_id' => $reservationId];
            }
            $events = $ledgerByReservation[$reservationId] ?? [];
            $byType = [];
            foreach ($events as $event) {
                $byType[(string) $event['event_type']][] = $event;
            }
            $reserveEvents = $byType[AssetLedger::TYPE_RESERVE] ?? [];
            if (count($reserveEvents) !== 1
                || (string) ($reserveEvents[0]['event_id'] ?? '') !== (string) $reservation['reserve_event_id']
                || (int) ($reserveEvents[0]['amount_minor'] ?? 0) !== $amount
                || !hash_equals(
                    (string) $reservation['reserve_request_hash'],
                    (string) ($reserveEvents[0]['request_hash'] ?? ''),
                )
            ) {
                $diffs[] = ['code' => 'reservation_reserve_event_mismatch', 'reservation_id' => $reservationId];
            }
            $releaseEvents = $byType[AssetLedger::TYPE_RELEASE] ?? [];
            $commitEvents = $byType[AssetLedger::TYPE_COMMIT] ?? [];
            $returnEvents = $byType[AssetLedger::TYPE_RETURN] ?? [];
            $status = (string) $reservation['status'];
            $terminalEventId = trim((string) ($reservation['terminal_event_id'] ?? ''));
            if ($status === AssetReservation::STATUS_RESERVED) {
                $activeReservedByAccount[$accountId] =
                    ($activeReservedByAccount[$accountId] ?? 0) + $amount;
                if ($returned !== 0
                    || $terminalEventId !== ''
                    || $releaseEvents !== []
                    || $commitEvents !== []
                    || $returnEvents !== []
                ) {
                    $diffs[] = ['code' => 'reserved_state_invalid', 'reservation_id' => $reservationId];
                }
            } elseif ($status === AssetReservation::STATUS_RELEASED) {
                if ($returned !== 0
                    || count($releaseEvents) !== 1
                    || $commitEvents !== []
                    || $returnEvents !== []
                    || (string) ($releaseEvents[0]['event_id'] ?? '') !== $terminalEventId
                    || (int) ($releaseEvents[0]['amount_minor'] ?? 0) !== $amount
                    || !$this->isHash(
                        (string) ($reservation['terminal_request_hash'] ?? ''),
                    )
                    || !hash_equals(
                        (string) $reservation['terminal_request_hash'],
                        (string) ($releaseEvents[0]['request_hash'] ?? ''),
                    )
                ) {
                    $diffs[] = ['code' => 'released_state_invalid', 'reservation_id' => $reservationId];
                }
            } elseif ($status === AssetReservation::STATUS_COMMITTED) {
                $returnedLedger = array_sum(array_map(
                    static fn (array $entry): int => (int) $entry['amount_minor'],
                    $returnEvents,
                ));
                if (count($commitEvents) !== 1
                    || $releaseEvents !== []
                    || (string) ($commitEvents[0]['event_id'] ?? '') !== $terminalEventId
                    || (int) ($commitEvents[0]['amount_minor'] ?? 0) !== $amount
                    || !$this->isHash(
                        (string) ($reservation['terminal_request_hash'] ?? ''),
                    )
                    || !hash_equals(
                        (string) $reservation['terminal_request_hash'],
                        (string) ($commitEvents[0]['request_hash'] ?? ''),
                    )
                    || $returnedLedger !== $returned
                ) {
                    $diffs[] = ['code' => 'committed_state_invalid', 'reservation_id' => $reservationId];
                }
            } else {
                $diffs[] = ['code' => 'reservation_status_invalid', 'reservation_id' => $reservationId];
            }
        }

        foreach ($ledgerByReservation as $reservationId => $_events) {
            if (!isset($reservations[$reservationId])) {
                $diffs[] = ['code' => 'ledger_reservation_missing', 'reservation_id' => $reservationId];
            }
        }
        foreach ($accounts as $accountId => $account) {
            if ((int) $account['reserved_minor']
                !== (int) ($activeReservedByAccount[$accountId] ?? 0)
            ) {
                $diffs[] = ['code' => 'active_reservation_sum_mismatch', 'account_id' => $accountId];
            }
        }

        return $diffs;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array<string,mixed>
     */
    private function facts(array $rows): array
    {
        $rowCounts = [];
        $rowHashes = [];
        foreach ($rows as $name => $factRows) {
            $rowCounts[$name] = count($factRows);
            $rowHashes[$name] = $this->hashPayload($factRows);
        }
        $obligations = array_map(
            static fn (array $row): array => [
                'reservation_id' => $row['reservation_id'],
                'status' => $row['status'],
                'amount_minor' => $row['amount_minor'],
                'returned_amount_minor' => $row['returned_amount_minor'],
            ],
            $rows['reservations'],
        );
        $rowHashes['obligations'] = $this->hashPayload($obligations);
        $rowHashes['combined'] = $this->hashPayload($rowHashes);

        return [
            'schema_fingerprints' => $this->schemaFingerprints(),
            'row_counts' => $rowCounts,
            'row_hashes' => $rowHashes,
            '_rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function prepareTarget(?array $targetDb): array
    {
        $database = strtolower(trim((string) ($targetDb['database'] ?? '')));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_* created with --mode=full',
            );
        }
        $type = strtolower(trim((string) ($targetDb['type'] ?? 'pgsql')));
        if ($type !== 'pgsql') {
            throw new \RuntimeException(self::ERROR_POSTGRESQL . ':' . $type);
        }
        $requested = [
            'type' => 'pgsql',
            'hostname' => (string) ($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string) ($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string) ($targetDb['username'] ?? 'weline'),
            'password' => (string) ($targetDb['password'] ?? ''),
            'prefix' => (string) ($targetDb['prefix'] ?? ''),
        ];

        if ($this->memoryTarget) {
            $guard = $this->fingerprintGuard ?? new DatabaseFingerprintGuard();
            return [
                'db' => $requested,
                'guard' => $guard,
                'fingerprint' => $guard->assertIsolatedDatabase($requested),
                'database' => $database,
            ];
        }

        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        if (!$cloneService instanceof MigrationCloneService) {
            throw new \LogicException('MigrationCloneService binding is unavailable');
        }
        $handle = null;
        foreach ($cloneService->list() as $candidate) {
            if ($candidate->database === $database) {
                $handle = $candidate;
                break;
            }
        }
        if ($handle === null) {
            throw new \RuntimeException(self::ERROR_UNREGISTERED_CLONE . ':' . $database);
        }
        if ($handle->mode !== MigrationCloneService::MODE_FULL) {
            throw new \RuntimeException(self::ERROR_FULL_CLONE . ':' . $database);
        }
        foreach (['type', 'hostname', 'hostport', 'database', 'username'] as $field) {
            if ((string) ($handle->config[$field] ?? '') !== (string) $requested[$field]) {
                throw new \RuntimeException(
                    self::ERROR_FINGERPRINT . ':target_' . $field . '_mismatch',
                );
            }
        }

        $guard = $cloneService->guardedFingerprint();
        $fingerprint = $guard->assertIsolatedDatabase($requested);
        if (!hash_equals($handle->fingerprint, $fingerprint)) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }
        ($this->targetBinder ?? new MigrationTargetBinder())->bindIsolated($requested);
        $this->rolloutGate = null;
        $this->checkpointService = null;

        return [
            'db' => $requested,
            'guard' => $guard,
            'fingerprint' => $fingerprint,
            'database' => $database,
        ];
    }

    private function runtimeRollout(): CustomerAssetRolloutGate
    {
        return $this->rolloutGate ??= $this->memoryTarget
            ? CustomerAssetRolloutGate::forTestingConfiguration()
            : CustomerAssetRolloutGate::forConnection(ConnectionFactory::getInstance());
    }

    private function checkpoint(DatabaseFingerprintGuard $guard): MigrationCheckpointService
    {
        return $this->checkpointService ??= new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(),
        );
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $snapshot
     */
    private function manifest(
        string $checkpointId,
        array $target,
        array $snapshot,
    ): MigrationManifest {
        $facts = $snapshot['facts'];

        return MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-shadow',
            'repo' => 'WelineFramework',
            'branch' => 'working-tree',
            'commit' => 'current-source',
            'connector_fingerprint' => $target['fingerprint'],
            'schema_fingerprints' => $facts['schema_fingerprints'],
            'row_counts' => $facts['row_counts'],
            'row_hashes' => $facts['row_hashes'],
            'watermarks' => [
                'website_id' => (int) (
                    $facts['_rows']['accounts'][0]['website_id'] ?? -1
                ),
                'account_count' => $facts['row_counts']['accounts'],
                'ledger_count' => $facts['row_counts']['ledger'],
                'reservation_count' => $facts['row_counts']['reservations'],
                'max_account_version' => $this->maxInt(
                    $facts['_rows']['accounts'],
                    'version',
                ),
            ],
            'backup_ref' => 'clone-full:' . $target['database'],
            'created_at' => gmdate('c'),
        ]);
    }

    /** @return array<string,string> */
    private function schemaFingerprints(): array
    {
        $out = [];
        foreach (self::FACT_MODELS as $name => $modelClass) {
            $out[$name] = hash('sha256', implode('|', [
                $modelClass,
                $modelClass::schema_table,
                implode(',', self::FACT_FIELDS[$name]),
            ]));
        }

        return $out;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'ok' => $snapshot['integrity_ok'],
            'sample_count' => $snapshot['sample_count'],
            'diff_count' => count($snapshot['diffs']),
            'diffs' => $snapshot['diffs'],
            'row_counts' => $snapshot['facts']['row_counts'],
            'fact_hash' => $snapshot['facts']['row_hashes']['combined'],
            'obligation_hash' => $snapshot['facts']['row_hashes']['obligations'],
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameFacts(array $left, array $right): bool
    {
        return $left['row_counts'] === $right['row_counts']
            && $left['schema_fingerprints'] === $right['schema_fingerprints']
            && hash_equals(
                (string) $left['row_hashes']['combined'],
                (string) $right['row_hashes']['combined'],
            );
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function retainsFacts(array $before, array $after): bool
    {
        foreach ([
            'accounts' => 'account_id',
            'ledger' => 'entry_id',
            'reservations' => 'reservation_id',
        ] as $name => $idField) {
            $afterIds = array_fill_keys(
                array_map(
                    static fn (array $row): string => (string) $row[$idField],
                    $after['_rows'][$name],
                ),
                true,
            );
            foreach ($before['_rows'][$name] as $row) {
                if (!isset($afterIds[(string) $row[$idField]])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(string $name, array $rows): array
    {
        $fields = self::FACT_FIELDS[$name];
        $intFields = self::INT_FIELDS[$name];

        return array_map(
            static function (array $row) use ($fields, $intFields): array {
                $out = [];
                foreach ($fields as $field) {
                    $value = $row[$field] ?? null;
                    $out[$field] = in_array($field, $intFields, true)
                        ? (int) $value
                        : ($value === null ? null : (string) $value);
                }
                return $out;
            },
            $rows,
        );
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     * @return array<string,mixed>|null
     */
    private function lastEventDetail(array $journal, string $event): ?array
    {
        for ($index = count($journal) - 1; $index >= 0; $index--) {
            if (($journal[$index]['event'] ?? null) === $event) {
                return $journal[$index]['detail'] ?? [];
            }
        }

        return null;
    }

    private function requireCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId === '') {
            throw new \InvalidArgumentException(self::ERROR_CHECKPOINT);
        }

        return $checkpointId;
    }

    private function assertScope(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(
                self::ERROR_SCOPE . ': pass --website=N; 0 is valid',
            );
        }
    }

    private function assertMemoryTarget(): void
    {
        if (!$this->memoryTarget) {
            throw new \LogicException('customer_asset_migration_memory_target_required');
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameIdentity(array $left, array $right): bool
    {
        return (string) $left['customer_id'] === (string) $right['customer_id']
            && (int) $left['website_id'] === (int) $right['website_id']
            && (string) $left['asset_code'] === (string) $right['asset_code']
            && (string) $left['namespace'] === (string) $right['namespace'];
    }

    private function isHash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param list<array<string,mixed>> $rows */
    private function maxInt(array $rows, string $field): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row[$field] ?? 0));
        }

        return $max;
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $payload): string
    {
        if (is_array($payload)) {
            if (!array_is_list($payload)) {
                ksort($payload, SORT_STRING);
            }
            foreach ($payload as $key => $value) {
                $payload[$key] = json_decode($this->canonicalJson($value), true);
            }
        }

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
