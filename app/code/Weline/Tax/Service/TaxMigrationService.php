<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Api\TaxShadowQuoteSourceInterface;

/**
 * Durable Tax shadow -> allowlist cutover (TASK-MIG-P3B).
 *
 * Production actions bind a registry-approved full clone before resolving the
 * quote source, Tax engine, LKG store or rollout control plane. No action may
 * infer success from process-local state.
 */
final class TaxMigrationService
{
    public const PHASE = 'p3b-tax';
    public const CAPABILITY = TaxRolloutGate::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p3b_tax_requires_isolated_database';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p3b_tax_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p3b_tax_full_clone_required';
    public const ERROR_SCOPE = 'mig_p3b_tax_scope_tuple_required';
    public const ERROR_NO_SAMPLE = 'mig_p3b_tax_shadow_sample_required';
    public const ERROR_MIXED_WINDOW = 'mig_p3b_tax_mixed_observation_window';
    public const ERROR_ENGINE_NOT_READY = 'mig_p3b_tax_engine_not_ready';
    public const ERROR_SHADOW_DIFF = 'mig_p3b_tax_shadow_diff';
    public const ERROR_LKG = 'mig_p3b_tax_verified_lkg_required';
    public const ERROR_CHECKPOINT = 'mig_p3b_tax_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p3b_tax_checkpoint_fingerprint_mismatch';
    public const ERROR_VERIFY = 'mig_p3b_tax_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p3b_tax_allowlist_scope_mismatch';
    public const ERROR_ROLLOUT_MODE = 'mig_p3b_tax_mode_not_shadow_or_allowlist';

    /** @var list<array<string,mixed>> */
    private array $memorySamples = [];

    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;
    private ?TaxShadowQuoteSourceInterface $quoteSource = null;
    private ?TaxRolloutGate $rolloutGate = null;
    private ?TaxLkgStore $lkgStore = null;
    private ?MigrationTargetBinder $targetBinder = null;
    private bool $memoryTarget = false;

    public function __construct()
    {
    }

    public static function forTesting(
        ?string $journalDir = null,
        ?TaxRolloutGate $rollout = null,
        ?TaxLkgStore $lkg = null,
    ): self {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? sys_get_temp_dir() . '/mig_p3btax_' . uniqid('', true),
        );

        $service = new self();
        $service->fingerprintGuard = $guard;
        $service->checkpointService = new MigrationCheckpointService($guard, $store);
        $service->rolloutGate = $rollout ?? TaxRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'shadow_sample_bp' => 10000,
        ]);
        $service->lkgStore = $lkg ?? TaxLkgStore::forTesting();
        $service->memoryTarget = true;

        return $service;
    }

    public function rollout(): TaxRolloutGate
    {
        return $this->runtimeRollout();
    }

    /**
     * Test-only deterministic quote injection. Production always resolves the
     * Checkout-owned read-only source after clone binding.
     *
     * @param array<string,mixed> $request
     */
    public function seedShadowQuote(array $request): void
    {
        if (!$this->memoryTarget) {
            throw new \LogicException('tax_shadow_seed_is_test_only');
        }
        $this->memorySamples[] = $request;
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function preflight(
        ?array $targetDb,
        int $websiteId,
        int $storeId,
        int $channelId,
    ): array {
        $target = $this->prepareTarget($targetDb);

        return $this->publicPreflight($this->inspectWindow(
            $target,
            $websiteId,
            $storeId,
            $channelId,
        ));
    }

    /**
     * Create checkpoint, run current engine vs frozen snapshot comparison and
     * persist exact-scope verified LKG. Rollout remains shadow.
     *
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function apply(
        ?array $targetDb,
        int $websiteId,
        int $storeId,
        int $channelId,
    ): array {
        $target = $this->prepareTarget($targetDb);
        $preflight = $this->inspectWindow($target, $websiteId, $storeId, $channelId);
        if (empty($preflight['ok'])) {
            return $preflight;
        }

        $rollout = $this->runtimeRollout();
        $rolloutConfiguration = $rollout->configuration();
        if (!empty($rolloutConfiguration['env_locked'])) {
            if ($rolloutConfiguration['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW) {
                throw new \RuntimeException('tax_rollout_env_lock_must_be_shadow_for_apply');
            }
        } else {
            $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        }

        $checkpointId = $this->newCheckpointId();
        $manifest = $this->manifest($checkpointId, $target, $preflight);
        $checkpoint = $this->checkpoint($target['guard']);
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p3b_tax_preflight_snapshot', [
            'database' => $target['database'],
            'scope' => [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ],
            'request_hashes' => $preflight['request_hashes'],
            'request_set_hash' => $preflight['request_set_hash'],
            'rule_snapshot' => $preflight['rule_snapshot'],
            'rule_snapshot_hash' => $preflight['rule_snapshot_hash'],
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $lkg = $this->runtimeLkg();
        $report = $this->compare(
            $preflight['requests'],
            $preflight['rule_snapshot'],
            $lkg,
        );
        if (empty($report['ok'])
            || (int)($report['unclassified_diff_count'] ?? 0) !== 0
            || empty($report['conserved'])
            || (float)($report['max_line_rounding_drift'] ?? INF) > 1.0
        ) {
            $checkpoint->appendJournal($checkpointId, 'p3b_tax_shadow_rejected', [
                'report' => $report,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => self::ERROR_SHADOW_DIFF,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => $target['database'],
                'mode' => $rollout->mode(self::CAPABILITY),
                'report' => $report,
            ];
        }

        $scopeKey = (string)($report['scope_key'] ?? '');
        $ruleSetHash = (string)($report['rule_set_hash'] ?? '');
        $verifiedLkg = $lkg->readVerified(
            TaxEngine::SCHEMA_VERSION,
            $ruleSetHash,
            $scopeKey,
        );
        if ($verifiedLkg === null) {
            $checkpoint->appendJournal($checkpointId, 'p3b_tax_lkg_rejected', [
                'scope_key' => $scopeKey,
                'rule_set_hash' => $ruleSetHash,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => self::ERROR_LKG,
                'checkpoint_id' => $checkpointId,
                'database' => $target['database'],
                'mode' => $rollout->mode(self::CAPABILITY),
            ];
        }

        $checkpoint->appendJournal($checkpointId, 'p3b_tax_shadow_applied', [
            'database' => $target['database'],
            'report' => $report,
            'lkg_scope_key' => $scopeKey,
            'lkg_rule_set_hash' => $ruleSetHash,
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist_ready' => false,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist_ready' => false,
            'report' => $report,
            'lkg_retained' => true,
            'snapshots_immutable' => true,
        ];
    }

    /**
     * Fresh checkpoint reload + current observation replay. This method does
     * not persist LKG or change rollout state.
     *
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
                'error' => (string)($fresh['error'] ?? 'migration_checkpoint_missing'),
                'fresh_journal' => $fresh,
            ];
        }

        $manifest = MigrationManifest::fromArray($stored['manifest']);
        $diffs = [];
        if ($manifest->phase !== self::PHASE . '-shadow') {
            $diffs[] = ['code' => 'mig_p3b_tax_checkpoint_phase_mismatch'];
        }
        if (!hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            $diffs[] = ['code' => self::ERROR_FINGERPRINT];
        }

        $preflightEvent = $this->lastEventDetail($stored['journal'], 'p3b_tax_preflight_snapshot');
        $applyEvent = $this->lastEventDetail($stored['journal'], 'p3b_tax_shadow_applied');
        if ($preflightEvent === null) {
            $diffs[] = ['code' => 'mig_p3b_tax_preflight_journal_missing'];
        }
        if ($applyEvent === null) {
            $diffs[] = ['code' => 'mig_p3b_tax_apply_journal_missing'];
        }

        $websiteId = (int)($manifest->watermarks['website_id'] ?? -1);
        $storeId = (int)($manifest->watermarks['store_id'] ?? -1);
        $channelId = (int)($manifest->watermarks['channel_id'] ?? -1);
        $current = $this->inspectWindow($target, $websiteId, $storeId, $channelId);
        if (empty($current['ok'])) {
            $diffs[] = [
                'code' => (string)($current['error'] ?? self::ERROR_NO_SAMPLE),
                'detail' => $current,
            ];
        }

        $snapshot = is_array($preflightEvent['rule_snapshot'] ?? null)
            ? $preflightEvent['rule_snapshot']
            : null;
        if ($snapshot === null) {
            $diffs[] = ['code' => 'mig_p3b_tax_rule_snapshot_missing'];
        }

        $report = null;
        if (!empty($current['ok']) && $snapshot !== null) {
            $requestSetHash = (string)($current['request_set_hash'] ?? '');
            if (!hash_equals(
                (string)($manifest->rowHashes['tax_shadow_request_set'] ?? ''),
                $requestSetHash,
            )) {
                $diffs[] = ['code' => 'mig_p3b_tax_request_set_changed'];
            }
            $snapshotHash = $this->hashPayload($snapshot);
            if (!hash_equals(
                (string)($manifest->rowHashes['tax_rule_snapshot'] ?? ''),
                $snapshotHash,
            )) {
                $diffs[] = ['code' => 'mig_p3b_tax_rule_snapshot_changed'];
            }
            $report = $this->compare($current['requests'], $snapshot, null);
            $storedReport = is_array($applyEvent['report'] ?? null) ? $applyEvent['report'] : [];
            if (empty($report['ok'])
                || !hash_equals(
                    (string)($storedReport['report_hash'] ?? ''),
                    (string)($report['report_hash'] ?? ''),
                )
            ) {
                $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
            }

            $scopeKey = (string)($report['scope_key'] ?? '');
            $ruleSetHash = (string)($report['rule_set_hash'] ?? '');
            if ($this->runtimeLkg()->readVerified(
                TaxEngine::SCHEMA_VERSION,
                $ruleSetHash,
                $scopeKey,
            ) === null) {
                $diffs[] = ['code' => self::ERROR_LKG];
            }
        }

        $rollout = $this->runtimeRollout()->configuration();
        if (!in_array(
            $rollout['mode'],
            [CommerceRolloutGateInterface::MODE_SHADOW, CommerceRolloutGateInterface::MODE_ALLOWLIST],
            true,
        )) {
            $diffs[] = ['code' => self::ERROR_ROLLOUT_MODE];
        }

        return [
            'ok' => $diffs === [],
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $rollout['mode'],
            'allowlist' => array_values($rollout['allowlist_rows']),
            'sample_count' => (int)($current['sample_count'] ?? 0),
            'report' => $report,
            'fresh_journal' => $fresh,
            'lkg_retained' => true,
            'snapshots_immutable' => true,
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
        int $storeId,
        int $channelId,
    ): array {
        $this->assertScopeTuple($websiteId, $storeId, $channelId);
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

        $target = $this->prepareTarget($targetDb);
        $checkpoint = $this->checkpoint($target['guard']);
        $stored = $checkpoint->store()?->load($checkpointId);
        if ($stored === null) {
            throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
        }
        $manifest = MigrationManifest::fromArray($stored['manifest']);
        $expected = [
            (int)($manifest->watermarks['website_id'] ?? -1),
            (int)($manifest->watermarks['store_id'] ?? -1),
            (int)($manifest->watermarks['channel_id'] ?? -1),
        ];
        if ($expected !== [$websiteId, $storeId, $channelId]) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_SCOPE_MISMATCH,
                'expected_scope' => $expected,
                'requested_scope' => [$websiteId, $storeId, $channelId],
            ];
        }

        $subject = TaxRolloutGate::tupleKey($websiteId, $storeId, $channelId);
        $rollout = $this->runtimeRollout();
        $rollout->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            [$subject],
        );
        $readback = $rollout->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_ALLOWLIST
            || !isset($readback['allowlist'][$subject])
            || count($readback['allowlist']) !== 1
        ) {
            throw new \RuntimeException('mig_p3b_tax_allowlist_readback_failed');
        }

        $checkpoint->appendJournal($checkpointId, 'p3b_tax_allowlist_applied', [
            'database' => $target['database'],
            'scope' => [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ],
            'subject' => $subject,
            'verified_manifest_hash' => (string)$verified['manifest_hash'],
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => $readback['mode'],
            'allowlist' => array_values($readback['allowlist_rows']),
            'fresh_verify' => true,
            'production_on' => false,
        ];
    }

    /**
     * mode=off only disables new Tax writes. Checkpoint, verified LKG and all
     * existing Tax snapshots remain immutable and readable.
     *
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function rollbackToModeOff(?array $targetDb, string $checkpointId): array
    {
        $target = $this->prepareTarget($targetDb);
        $checkpointId = $this->requireCheckpointId($checkpointId);
        $checkpoint = $this->checkpoint($target['guard']);
        $stored = $checkpoint->store()?->load($checkpointId);
        if ($stored === null) {
            throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
        }
        $manifest = MigrationManifest::fromArray($stored['manifest']);
        if (!hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }

        $checkpoint->rollbackGuard($checkpointId);
        $rollout = $this->runtimeRollout();
        $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
        $readback = $rollout->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $readback['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p3b_tax_rollback_readback_failed');
        }
        $checkpoint->appendJournal($checkpointId, 'p3b_tax_mode_off', [
            'database' => $target['database'],
            'lkg_retained' => true,
            'snapshots_immutable' => true,
            'checkpoint_retained' => true,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
            'lkg_retained' => true,
            'snapshots_immutable' => true,
            'checkpoint_retained' => true,
            'continue_forward' => true,
        ];
    }

    /**
     * @param array{
     *     db:array<string,mixed>,
     *     guard:DatabaseFingerprintGuard,
     *     fingerprint:string,
     *     database:string
     * } $target
     * @return array<string,mixed>
     */
    private function inspectWindow(
        array $target,
        int $websiteId,
        int $storeId,
        int $channelId,
    ): array {
        $this->assertScopeTuple($websiteId, $storeId, $channelId);
        $window = $this->observationWindow($websiteId, $storeId, $channelId);
        $requests = $window['requests'];
        $sampleCount = count($requests);
        $base = [
            'phase' => self::PHASE,
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'sample_count' => $sampleCount,
            'required_sample_count' => TaxShadowComparator::MIN_OBSERVATION_QUOTES,
            'scanned_count' => $window['scanned_count'],
            'rejected_count' => $window['rejected_count'],
            'duplicate_count' => $window['duplicate_count'],
            'shared_db_apply_forbidden' => true,
            'full_clone_required' => true,
        ];
        if ($sampleCount < TaxShadowComparator::MIN_OBSERVATION_QUOTES) {
            return $base + [
                'ok' => false,
                'error' => self::ERROR_NO_SAMPLE,
                'apply_ready' => false,
            ];
        }

        $primary = $this->primaryEngine();
        $requestHashes = [];
        $seen = [];
        $snapshot = null;
        $scopeKey = '';
        $ruleSetHash = '';
        foreach ($requests as $index => $request) {
            $requestHash = $this->hashPayload($request);
            if (isset($seen[$requestHash])) {
                return $base + [
                    'ok' => false,
                    'error' => self::ERROR_MIXED_WINDOW,
                    'detail' => 'duplicate_request:' . $index,
                    'apply_ready' => false,
                ];
            }
            $seen[$requestHash] = true;
            $requestHashes[] = $requestHash;
            try {
                $current = $primary->ruleSetSnapshot($request);
            } catch (\Throwable $exception) {
                return $base + [
                    'ok' => false,
                    'error' => self::ERROR_MIXED_WINDOW,
                    'detail' => $exception->getMessage(),
                    'apply_ready' => false,
                ];
            }
            $currentScope = (string)($current['scope_key'] ?? '');
            $currentHash = (string)($current['rule_set_hash'] ?? '');
            if ($snapshot === null) {
                $snapshot = $current;
                $scopeKey = $currentScope;
                $ruleSetHash = $currentHash;
                continue;
            }
            if ($currentScope !== $scopeKey || $currentHash !== $ruleSetHash) {
                return $base + [
                    'ok' => false,
                    'error' => self::ERROR_MIXED_WINDOW,
                    'detail' => 'scope_or_rule_set_changed:' . $index,
                    'apply_ready' => false,
                ];
            }
        }
        if ($snapshot === null) {
            return $base + [
                'ok' => false,
                'error' => self::ERROR_NO_SAMPLE,
                'apply_ready' => false,
            ];
        }
        try {
            $primary->calculate($requests[0]);
        } catch (\Throwable $exception) {
            return $base + [
                'ok' => false,
                'error' => self::ERROR_ENGINE_NOT_READY,
                'detail' => $exception->getMessage(),
                'apply_ready' => false,
            ];
        }

        $requestSetHash = $this->hashPayload($requestHashes);
        $ruleSnapshotHash = $this->hashPayload($snapshot);
        $rollout = $this->runtimeRollout()->configuration();

        return $base + [
            'ok' => true,
            'apply_ready' => true,
            'requests' => $requests,
            'request_hashes' => $requestHashes,
            'request_set_hash' => $requestSetHash,
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'scope_key' => $scopeKey,
            'rule_set_hash' => $ruleSetHash,
            'rule_snapshot' => $snapshot,
            'rule_snapshot_hash' => $ruleSnapshotHash,
            'mode' => $rollout['mode'],
            'allowlist' => array_values($rollout['allowlist_rows']),
            'env_locked' => $rollout['env_locked'],
            'shadow_sample_bp' => $rollout['shadow_sample_bp'],
        ];
    }

    /**
     * @return array{
     *     requests:list<array<string,mixed>>,
     *     scanned_count:int,
     *     rejected_count:int,
     *     duplicate_count:int,
     *     request_hashes:list<string>
     * }
     */
    private function observationWindow(int $websiteId, int $storeId, int $channelId): array
    {
        if ($this->memoryTarget) {
            $hashes = array_map(
                fn (array $request): string => $this->hashPayload($request),
                $this->memorySamples,
            );

            return [
                'requests' => array_slice(
                    $this->memorySamples,
                    0,
                    TaxShadowComparator::MIN_OBSERVATION_QUOTES,
                ),
                'scanned_count' => count($this->memorySamples),
                'rejected_count' => 0,
                'duplicate_count' => count($hashes) - count(array_unique($hashes)),
                'request_hashes' => array_values(array_unique($hashes)),
            ];
        }

        return $this->runtimeQuoteSource()->observationWindow(
            $websiteId,
            $storeId,
            $channelId,
            TaxShadowComparator::MIN_OBSERVATION_QUOTES,
        );
    }

    /**
     * @param list<array<string,mixed>> $requests
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function compare(array $requests, array $snapshot, ?TaxLkgStore $lkg): array
    {
        return (new TaxShadowComparator(
            $this->primaryEngine(),
            TaxEngine::fromSnapshot($snapshot),
            $lkg,
        ))->observe($requests);
    }

    private function primaryEngine(): TaxEngine
    {
        return $this->memoryTarget
            ? TaxEngine::forTesting($this->runtimeLkg())
            : new TaxEngine();
    }

    private function runtimeQuoteSource(): TaxShadowQuoteSourceInterface
    {
        if ($this->quoteSource !== null) {
            return $this->quoteSource;
        }
        $source = ObjectManager::create(TaxShadowQuoteSourceInterface::class, [], false);
        if (!$source instanceof TaxShadowQuoteSourceInterface) {
            throw new \RuntimeException('tax_shadow_quote_source_unavailable');
        }

        return $source;
    }

    private function runtimeRollout(): TaxRolloutGate
    {
        if ($this->rolloutGate !== null) {
            return $this->rolloutGate;
        }

        return TaxRolloutGate::forConnection(ConnectionFactory::getInstance());
    }

    private function runtimeLkg(): TaxLkgStore
    {
        return $this->lkgStore ?? new TaxLkgStore();
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array{
     *     db:array<string,mixed>,
     *     guard:DatabaseFingerprintGuard,
     *     fingerprint:string,
     *     database:string
     * }
     */
    private function prepareTarget(?array $targetDb): array
    {
        $db = $this->requireIsolatedTarget($targetDb);
        if ($this->memoryTarget) {
            $guard = $this->fingerprintGuard ?? new DatabaseFingerprintGuard();
            $fingerprint = $guard->assertIsolatedDatabase($db);
            return [
                'db' => $db,
                'guard' => $guard,
                'fingerprint' => $fingerprint,
                'database' => (string)$db['database'],
            ];
        }

        /** @var MigrationCloneService $clones */
        $clones = ObjectManager::getInstance(MigrationCloneService::class);
        $handle = null;
        foreach ($clones->list() as $candidate) {
            if ($candidate->database === (string)$db['database']) {
                $handle = $candidate;
                break;
            }
        }
        if ($handle === null) {
            throw new \RuntimeException(self::ERROR_UNREGISTERED_CLONE);
        }
        if ($handle->mode !== MigrationCloneService::MODE_FULL) {
            throw new \RuntimeException(self::ERROR_FULL_CLONE);
        }
        $guard = $clones->guardedFingerprint();
        $fingerprint = $guard->assertIsolatedDatabase($db);
        if (!hash_equals($handle->fingerprint, $fingerprint)) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }
        ($this->targetBinder ?? new MigrationTargetBinder())->bindIsolated($db);

        return [
            'db' => $db,
            'guard' => $guard,
            'fingerprint' => $fingerprint,
            'database' => (string)$db['database'],
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        $database = strtolower(trim((string)($targetDb['database'] ?? '')));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_* created with --mode=full',
            );
        }
        $db = [
            'type' => (string)($targetDb['type'] ?? 'pgsql'),
            'hostname' => (string)($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($targetDb['username'] ?? 'weline'),
            'password' => (string)($targetDb['password'] ?? ''),
            'prefix' => (string)($targetDb['prefix'] ?? ''),
        ];
        ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())->assertIsolatedDatabase($db);

        return $db;
    }

    /**
     * @param array{
     *     db:array<string,mixed>,
     *     guard:DatabaseFingerprintGuard,
     *     fingerprint:string,
     *     database:string
     * } $target
     * @param array<string,mixed> $preflight
     */
    private function manifest(string $checkpointId, array $target, array $preflight): MigrationManifest
    {
        return MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-shadow',
            'repo' => 'framework',
            'branch' => 'local',
            'commit' => 'mig-p3b-tax',
            'connector_fingerprint' => $target['fingerprint'],
            'schema_fingerprints' => [
                'tax_engine_schema' => hash('sha256', TaxEngine::SCHEMA_VERSION),
            ],
            'row_counts' => [
                'tax_shadow_quote' => (int)$preflight['sample_count'],
            ],
            'row_hashes' => [
                'tax_shadow_request_set' => (string)$preflight['request_set_hash'],
                'tax_rule_snapshot' => (string)$preflight['rule_snapshot_hash'],
                'tax_rule_set' => (string)$preflight['rule_set_hash'],
            ],
            'watermarks' => [
                'website_id' => (int)$preflight['website_id'],
                'store_id' => (int)$preflight['store_id'],
                'channel_id' => (int)$preflight['channel_id'],
                'scanned_count' => (int)$preflight['scanned_count'],
                'rejected_count' => (int)$preflight['rejected_count'],
                'duplicate_count' => (int)$preflight['duplicate_count'],
            ],
            'backup_ref' => 'clone-full:' . $target['database'],
            'created_at' => gmdate('c'),
        ]);
    }

    private function checkpoint(DatabaseFingerprintGuard $guard): MigrationCheckpointService
    {
        return $this->checkpointService ?? new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(),
        );
    }

    private function requireCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId === '') {
            throw new \RuntimeException(self::ERROR_CHECKPOINT . ': pass --checkpoint=ID');
        }

        return $checkpointId;
    }

    private function newCheckpointId(): string
    {
        return 'p3btax-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    private function assertScopeTuple(int $websiteId, int $storeId, int $channelId): void
    {
        if ($websiteId < 0 || $storeId < 1 || $channelId < 1) {
            throw new \InvalidArgumentException(
                self::ERROR_SCOPE . ': require website>=0, store>=1, channel>=1',
            );
        }
    }

    /**
     * @param list<array{at?:string,event?:string,detail?:array<string,mixed>}> $journal
     * @return array<string,mixed>|null
     */
    private function lastEventDetail(array $journal, string $event): ?array
    {
        for ($index = count($journal) - 1; $index >= 0; $index--) {
            $row = $journal[$index] ?? null;
            if (!is_array($row) || (string)($row['event'] ?? '') !== $event) {
                continue;
            }
            return is_array($row['detail'] ?? null) ? $row['detail'] : [];
        }

        return null;
    }

    private function hashPayload(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string,mixed> $preflight */
    private function publicPreflight(array $preflight): array
    {
        unset(
            $preflight['requests'],
            $preflight['request_hashes'],
            $preflight['rule_snapshot'],
        );

        return $preflight;
    }
}
