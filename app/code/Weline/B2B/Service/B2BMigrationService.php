<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderPriceSnapshot;
use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\B2B\Model\B2BQuoteToken;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
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
 * Clone-bound, checkpointed B2B version mapping and Checkout cutover.
 *
 * Production paths only read business facts from a registry-approved full
 * PostgreSQL clone. Apply stops at shadow; allowlist is a separate action
 * after fresh checkpoint verification.
 */
final class B2BMigrationService
{
    public const PHASE = 'p4c-b2b';
    public const CAPABILITY = B2BService::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p4c_b2b_requires_isolated_database';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p4c_b2b_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p4c_b2b_full_clone_required';
    public const ERROR_SCOPE = 'mig_p4c_b2b_website_required';
    public const ERROR_NO_SAMPLE = 'mig_p4c_b2b_durable_sample_required';
    public const ERROR_INTEGRITY = 'mig_p4c_b2b_fact_integrity_failed';
    public const ERROR_SHADOW_DIFF = 'mig_p4c_b2b_shadow_diff';
    public const ERROR_CHECKPOINT = 'mig_p4c_b2b_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p4c_b2b_checkpoint_fingerprint_mismatch';
    public const ERROR_VERIFY = 'mig_p4c_b2b_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p4c_b2b_allowlist_scope_mismatch';
    public const ERROR_ROLLOUT_MODE = 'mig_p4c_b2b_rollout_mode_invalid';

    /** @var array<string,list<string>> */
    private const FACT_FIELDS = [
        'groups' => [
            'group_id',
            'website_id',
            'code',
            'status',
            'group_version',
        ],
        'memberships' => [
            'customer_id',
            'website_id',
            'group_id',
            'membership_version',
        ],
        'price_lists' => [
            'list_id',
            'group_id',
            'website_id',
            'version',
            'channel_id',
            'active',
        ],
        'items' => [
            'list_id',
            'list_version',
            'sku',
            'amount_minor',
        ],
        'quotes' => [
            'token_id',
            'customer_id',
            'website_id',
            'sku',
            'retail_amount_minor',
            'amount_minor',
            'source',
            'group_id',
            'price_list_id',
            'version',
            'channel_id',
            'rule_stack',
            'fingerprint',
            'issued_at_epoch',
            'expires_at_epoch',
            'status',
            'consumed_order_ref',
        ],
        'snapshots' => [
            'order_ref',
            'token_id',
            'customer_id',
            'website_id',
            'sku',
            'retail_amount_minor',
            'amount_minor',
            'source',
            'group_id',
            'price_list_id',
            'version',
            'channel_id',
            'rule_stack',
            'payload_hash',
            'created_at_epoch',
        ],
    ];

    /** @var array<string,class-string<Model>> */
    private const FACT_MODELS = [
        'groups' => CustomerGroupRecord::class,
        'memberships' => CustomerGroupMembershipRecord::class,
        'price_lists' => PriceListRecord::class,
        'items' => PriceListItemRecord::class,
        'quotes' => B2BQuoteTokenRecord::class,
        'snapshots' => B2BOrderPriceSnapshotRecord::class,
    ];

    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;
    private ?MigrationTargetBinder $targetBinder = null;
    private ?B2BRolloutGate $rolloutGate = null;
    private ?B2BPriceEngine $engine = null;
    private ?B2BService $memoryB2B = null;
    private bool $memoryTarget = false;
    private bool $forceShadowMismatch = false;

    /** @var list<array{group_id:string,website_id:int,code:string,customer_id:string}> */
    private array $memoryGroups = [];

    /**
     * @var list<array{
     *   list_id:string,
     *   group_id:string,
     *   website_id:int,
     *   version:int,
     *   sku_amounts:array<string,int>,
     *   channel_id:?string
     * }>
     */
    private array $memoryLists = [];

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
                $journalDir ?? sys_get_temp_dir() . '/mig_p4cb2b_' . uniqid('', true),
            ),
        );
        $service->rolloutGate = B2BRolloutGate::forTestingConfiguration();
        $service->memoryB2B = B2BService::forTesting($service->rolloutGate);
        $service->engine = $service->memoryB2B->engine();

        return $service;
    }

    public function b2b(): B2BService
    {
        if ($this->memoryB2B === null) {
            throw new \LogicException('b2b_migration_facade_is_test_only');
        }

        return $this->memoryB2B;
    }

    public function rollout(): B2BRolloutGate
    {
        return $this->runtimeRollout();
    }

    /**
     * Test-only fixture. Production preflight reads clone ORM rows.
     *
     * @param array{group_id:string,website_id?:int,code:string,customer_id:string} $seed
     */
    public function seedGroup(array $seed): void
    {
        $this->assertMemoryTarget();
        $row = [
            'group_id' => trim((string) ($seed['group_id'] ?? '')),
            'website_id' => (int) ($seed['website_id'] ?? 0),
            'code' => trim((string) ($seed['code'] ?? '')),
            'customer_id' => trim((string) ($seed['customer_id'] ?? '')),
        ];
        $this->memoryGroups[] = $row;
        $this->b2b()->seedGroup($row['group_id'], $row['website_id'], $row['code']);
        $this->b2b()->assignCustomer($row['customer_id'], $row['group_id']);
    }

    /**
     * Test-only fixture. Production preflight reads clone ORM rows.
     *
     * @param array{
     *   list_id:string,
     *   group_id:string,
     *   website_id?:int,
     *   version:int,
     *   sku_amounts:array<string,int>,
     *   channel_id?:string|null
     * } $seed
     */
    public function seedPriceList(array $seed): void
    {
        $this->assertMemoryTarget();
        $amounts = is_array($seed['sku_amounts'] ?? null)
            ? array_map('intval', $seed['sku_amounts'])
            : [];
        ksort($amounts, SORT_STRING);
        $row = [
            'list_id' => trim((string) ($seed['list_id'] ?? '')),
            'group_id' => trim((string) ($seed['group_id'] ?? '')),
            'website_id' => (int) ($seed['website_id'] ?? 0),
            'version' => (int) ($seed['version'] ?? 1),
            'sku_amounts' => $amounts,
            'channel_id' => $this->optionalString($seed['channel_id'] ?? null),
        ];
        $this->memoryLists[] = $row;
        $this->b2b()->seedPriceList(
            $row['list_id'],
            $row['group_id'],
            $row['website_id'],
            $row['version'],
            $row['sku_amounts'],
            $row['channel_id'],
        );
    }

    /**
     * Compatibility test seam. Expected values are deliberately ignored:
     * migration shadow samples are derived independently from durable facts.
     *
     * @param array<string,mixed> $seed
     */
    public function seedQuoteSample(array $seed): void
    {
        $this->assertMemoryTarget();
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

        $checkpointId = 'p4cb2b-' . gmdate('YmdHis') . '-'
            . substr(bin2hex(random_bytes(3)), 0, 6);
        $manifest = $this->manifest($checkpointId, $target, $snapshot);
        $checkpoint = $this->checkpoint($target['guard']);
        // The immutable business snapshot exists before the first rollout write.
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p4c_b2b_preflight_snapshot', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'mode_before' => $modeBefore,
            'row_counts' => $snapshot['facts']['row_counts'],
            'fact_hash' => $snapshot['facts']['row_hashes']['combined'],
            'version_mapping_hash' => $snapshot['facts']['row_hashes']['version_mapping'],
            'sample_hash' => $snapshot['facts']['row_hashes']['shadow_samples'],
            'business_writes' => 0,
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        $configuration = $rollout->configuration();
        if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4c_b2b_shadow_readback_failed');
        }

        $report = $this->shadowReport(
            $snapshot['samples'],
            $snapshot['version_mapping'],
        );
        if (empty($report['ok']) || (int) $report['unclassified_diff_count'] !== 0) {
            $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
            $checkpoint->appendJournal($checkpointId, 'p4c_b2b_shadow_rejected', [
                'database' => $target['database'],
                'report' => $report,
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
                'report' => $report,
                'business_writes' => 0,
            ];
        }

        // Prove shadow changed no group/list/quote/snapshot business fact.
        $after = $this->snapshot($websiteId);
        if (!$this->sameFacts($snapshot['facts'], $after['facts'])) {
            $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
            throw new \RuntimeException('mig_p4c_b2b_shadow_changed_business_facts');
        }

        $checkpoint->appendJournal($checkpointId, 'p4c_b2b_shadow_applied', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'report' => $report,
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'business_writes' => 0,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'fresh_verify_required' => true,
            'fact_hash' => $after['facts']['row_hashes']['combined'],
            'row_counts' => $after['facts']['row_counts'],
            'mapping_count' => count($after['version_mapping']),
            'sample_count' => count($after['samples']),
            'report' => $report,
            'business_writes' => 0,
            'checkout_allowlisted' => false,
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
            $diffs[] = ['code' => 'mig_p4c_b2b_checkpoint_phase_mismatch'];
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

        $configuration = $this->runtimeRollout()->configuration();
        if (!in_array($configuration['mode'], [
            CommerceRolloutGateInterface::MODE_SHADOW,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
        ], true)) {
            $diffs[] = ['code' => self::ERROR_ROLLOUT_MODE];
        }
        if ($configuration['mode'] === CommerceRolloutGateInterface::MODE_ALLOWLIST
            && array_keys($configuration['allowlist']) !== [B2BRolloutGate::scopeKey($websiteId)]
        ) {
            $diffs[] = ['code' => self::ERROR_SCOPE_MISMATCH];
        }

        $report = $this->shadowReport(
            $snapshot['samples'],
            $snapshot['version_mapping'],
        );
        $applyEvent = $this->lastEventDetail(
            $stored['journal'],
            'p4c_b2b_shadow_applied',
        );
        if ($applyEvent === null) {
            $diffs[] = ['code' => 'mig_p4c_b2b_apply_journal_missing'];
        } elseif (!hash_equals(
            (string) ($applyEvent['report']['report_hash'] ?? ''),
            (string) ($report['report_hash'] ?? ''),
        )) {
            $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
        }
        if (empty($report['ok'])) {
            $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
        }

        return [
            'ok' => $diffs === [],
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $configuration['mode'],
            'allowlist' => $configuration['allowlist_rows'],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'mapping_count' => count($snapshot['version_mapping']),
            'sample_count' => count($snapshot['samples']),
            'report' => $report,
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
        $subject = B2BRolloutGate::scopeKey($websiteId);
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
            throw new \RuntimeException('mig_p4c_b2b_allowlist_readback_failed');
        }
        $this->checkpoint($target['guard'])->appendJournal(
            $checkpointId,
            'p4c_b2b_allowlist_applied',
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
            'checkout_allowlisted' => true,
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

        // New allowlisted quote/order facts may exist. Emergency mode-off must
        // remain available when current facts are internally valid.
        $beforeSnapshot = $this->snapshot($websiteId);
        if (!$beforeSnapshot['integrity_ok']) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_INTEGRITY,
                'diffs' => $beforeSnapshot['diffs'],
            ];
        }
        $factsBefore = $beforeSnapshot['facts'];
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
            throw new \RuntimeException('mig_p4c_b2b_rollback_readback_failed');
        }
        $factsAfter = $this->snapshot($websiteId)['facts'];
        if (!$this->sameFacts($factsBefore, $factsAfter)) {
            throw new \RuntimeException('mig_p4c_b2b_rollback_changed_business_facts');
        }
        $checkpoint->appendJournal($checkpointId, 'p4c_b2b_mode_off', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $factsAfter['row_hashes']['combined'],
            'row_counts' => $factsAfter['row_counts'],
            'snapshots_retained' => true,
            'retail_path_continues' => true,
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
            'fact_hash' => $factsAfter['row_hashes']['combined'],
            'row_counts' => $factsAfter['row_counts'],
            'snapshots_retained' => true,
            'snapshot_count' => $factsAfter['row_counts']['snapshots'],
            'quote_count' => $factsAfter['row_counts']['quotes'],
            'retail_path_continues' => true,
            'b2b_candidate_closed' => true,
            'checkpoint_retained' => true,
        ];
    }

    /**
     * @return array{
     *   sample_count:int,
     *   mapping_count:int,
     *   integrity_ok:bool,
     *   apply_ready:bool,
     *   diffs:list<array<string,mixed>>,
     *   rows:array<string,list<array<string,mixed>>>,
     *   version_mapping:array<string,array<string,mixed>>,
     *   samples:list<array<string,mixed>>,
     *   facts:array<string,mixed>
     * }
     */
    private function snapshot(int $websiteId): array
    {
        $rows = $this->scopedRows($websiteId);
        $diffs = $this->integrityDiffs($rows, $websiteId);
        [$versionMapping, $mappingDiffs] = $this->versionMapping($rows);
        [$samples, $sampleDiffs] = $this->shadowSamples($rows);
        $diffs = array_merge($diffs, $mappingDiffs, $sampleDiffs);
        $facts = $this->facts($rows, $versionMapping, $samples);

        return [
            'sample_count' => count($samples),
            'mapping_count' => count($versionMapping),
            'integrity_ok' => $diffs === [],
            'apply_ready' => $samples !== [] && $diffs === [],
            'diffs' => $diffs,
            'rows' => $rows,
            'version_mapping' => $versionMapping,
            'samples' => $samples,
            'facts' => $facts,
        ];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function scopedRows(int $websiteId): array
    {
        if ($this->memoryTarget) {
            return $this->memoryRows($websiteId);
        }

        $groups = $this->fetchScoped(
            CustomerGroupRecord::class,
            CustomerGroupRecord::schema_fields_WEBSITE_ID,
            $websiteId,
            self::FACT_FIELDS['groups'],
        );
        $memberships = $this->fetchScoped(
            CustomerGroupMembershipRecord::class,
            CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID,
            $websiteId,
            self::FACT_FIELDS['memberships'],
        );
        $priceLists = $this->fetchScoped(
            PriceListRecord::class,
            PriceListRecord::schema_fields_WEBSITE_ID,
            $websiteId,
            self::FACT_FIELDS['price_lists'],
        );
        $listKeys = [];
        foreach ($priceLists as $row) {
            $listKeys[$this->listKey((string) $row['list_id'], (int) $row['version'])] = true;
        }
        $items = [];
        foreach ($this->fetchAll(PriceListItemRecord::class, self::FACT_FIELDS['items']) as $row) {
            if (isset($listKeys[$this->listKey(
                (string) $row['list_id'],
                (int) $row['list_version'],
            )])) {
                $items[] = $row;
            }
        }
        $quotes = $this->fetchScoped(
            B2BQuoteTokenRecord::class,
            B2BQuoteTokenRecord::schema_fields_WEBSITE_ID,
            $websiteId,
            self::FACT_FIELDS['quotes'],
        );
        $snapshots = $this->fetchScoped(
            B2BOrderPriceSnapshotRecord::class,
            B2BOrderPriceSnapshotRecord::schema_fields_WEBSITE_ID,
            $websiteId,
            self::FACT_FIELDS['snapshots'],
        );

        foreach ($quotes as &$quote) {
            $quote['rule_stack'] = $this->decodeRules($quote['rule_stack'] ?? '[]');
        }
        unset($quote);
        foreach ($snapshots as &$snapshot) {
            $snapshot['rule_stack'] = $this->decodeRules($snapshot['rule_stack'] ?? '[]');
        }
        unset($snapshot);

        return [
            'groups' => $groups,
            'memberships' => $memberships,
            'price_lists' => $priceLists,
            'items' => $items,
            'quotes' => $quotes,
            'snapshots' => $snapshots,
        ];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function memoryRows(int $websiteId): array
    {
        $groups = [];
        $memberships = [];
        foreach ($this->memoryGroups as $seed) {
            if ($seed['website_id'] !== $websiteId) {
                continue;
            }
            $groups[] = [
                'group_id' => $seed['group_id'],
                'website_id' => $seed['website_id'],
                'code' => $seed['code'],
                'status' => 'active',
                'group_version' => 1,
            ];
            $memberships[] = [
                'customer_id' => $seed['customer_id'],
                'website_id' => $seed['website_id'],
                'group_id' => $seed['group_id'],
                'membership_version' => 1,
            ];
        }
        $priceLists = [];
        $items = [];
        foreach ($this->memoryLists as $seed) {
            if ($seed['website_id'] !== $websiteId) {
                continue;
            }
            $priceLists[] = [
                'list_id' => $seed['list_id'],
                'group_id' => $seed['group_id'],
                'website_id' => $seed['website_id'],
                'version' => $seed['version'],
                'channel_id' => $seed['channel_id'],
                'active' => 1,
            ];
            foreach ($seed['sku_amounts'] as $sku => $amount) {
                $items[] = [
                    'list_id' => $seed['list_id'],
                    'list_version' => $seed['version'],
                    'sku' => (string) $sku,
                    'amount_minor' => (int) $amount,
                ];
            }
        }

        return [
            'groups' => $groups,
            'memberships' => $memberships,
            'price_lists' => $priceLists,
            'items' => $items,
            'quotes' => [],
            'snapshots' => [],
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return list<array<string,mixed>>
     */
    private function integrityDiffs(array $rows, int $websiteId): array
    {
        $diffs = [];
        $groups = [];
        foreach ($rows['groups'] as $group) {
            $id = (string) $group['group_id'];
            if (isset($groups[$id])) {
                $diffs[] = ['code' => 'b2b_group_duplicate', 'group_id' => $id];
            }
            if ((int) $group['website_id'] !== $websiteId) {
                $diffs[] = ['code' => 'b2b_group_scope_mismatch', 'group_id' => $id];
            }
            $groups[$id] = $group;
        }

        $memberships = [];
        foreach ($rows['memberships'] as $membership) {
            $key = (string) $membership['customer_id'] . '@' . (int) $membership['website_id'];
            if (isset($memberships[$key])) {
                $diffs[] = ['code' => 'b2b_membership_duplicate', 'membership' => $key];
            }
            $group = $groups[(string) $membership['group_id']] ?? null;
            if ($group === null || (int) $group['website_id'] !== (int) $membership['website_id']) {
                $diffs[] = ['code' => 'b2b_membership_group_mismatch', 'membership' => $key];
            }
            $memberships[$key] = $membership;
        }

        $lists = [];
        foreach ($rows['price_lists'] as $list) {
            $key = $this->listKey((string) $list['list_id'], (int) $list['version']);
            if (isset($lists[$key])) {
                $diffs[] = ['code' => 'b2b_price_list_revision_duplicate', 'list' => $key];
            }
            $group = $groups[(string) $list['group_id']] ?? null;
            if ($group === null || (int) $group['website_id'] !== (int) $list['website_id']) {
                $diffs[] = ['code' => 'b2b_price_list_group_mismatch', 'list' => $key];
            }
            $lists[$key] = $list;
        }

        $items = [];
        foreach ($rows['items'] as $item) {
            $listKey = $this->listKey(
                (string) $item['list_id'],
                (int) $item['list_version'],
            );
            $itemKey = $listKey . '#' . (string) $item['sku'];
            if (!isset($lists[$listKey])) {
                $diffs[] = ['code' => 'b2b_price_item_orphan', 'item' => $itemKey];
            }
            if (isset($items[$itemKey])) {
                $diffs[] = ['code' => 'b2b_price_item_duplicate', 'item' => $itemKey];
            }
            if ((int) $item['amount_minor'] < 0) {
                $diffs[] = ['code' => 'b2b_price_item_amount_invalid', 'item' => $itemKey];
            }
            $items[$itemKey] = $item;
        }

        $quoteByToken = [];
        foreach ($rows['quotes'] as $quote) {
            $tokenId = (string) $quote['token_id'];
            try {
                $this->assertQuote($quote);
            } catch (\Throwable $exception) {
                $diffs[] = [
                    'code' => 'b2b_quote_invalid',
                    'token_id' => $tokenId,
                    'message' => $exception->getMessage(),
                ];
            }
            $this->assertVersionReference($quote, $groups, $lists, $diffs, 'quote', $tokenId);
            $quoteByToken[$tokenId] = $quote;
        }

        foreach ($rows['snapshots'] as $snapshot) {
            $orderRef = (string) $snapshot['order_ref'];
            try {
                $this->assertSnapshot($snapshot);
            } catch (\Throwable $exception) {
                $diffs[] = [
                    'code' => 'b2b_order_snapshot_invalid',
                    'order_ref' => $orderRef,
                    'message' => $exception->getMessage(),
                ];
            }
            $this->assertVersionReference(
                $snapshot,
                $groups,
                $lists,
                $diffs,
                'snapshot',
                $orderRef,
            );
            $token = $quoteByToken[(string) $snapshot['token_id']] ?? null;
            if ($token === null
                || (string) ($token['consumed_order_ref'] ?? '') !== $orderRef
                || (string) $token['customer_id'] !== (string) $snapshot['customer_id']
            ) {
                $diffs[] = [
                    'code' => 'b2b_snapshot_token_mismatch',
                    'order_ref' => $orderRef,
                ];
            }
        }

        return $diffs;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array{0:array<string,array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function versionMapping(array $rows): array
    {
        $itemsByList = [];
        foreach ($rows['items'] as $item) {
            $itemsByList[$this->listKey(
                (string) $item['list_id'],
                (int) $item['list_version'],
            )][] = $item;
        }
        $mapping = [];
        $diffs = [];
        foreach ($rows['price_lists'] as $list) {
            $key = $this->listKey((string) $list['list_id'], (int) $list['version']);
            $items = $itemsByList[$key] ?? [];
            $this->sortRows($items);
            $candidate = [
                'list_id' => (string) $list['list_id'],
                'version' => (int) $list['version'],
                'group_id' => (string) $list['group_id'],
                'website_id' => (int) $list['website_id'],
                'channel_id' => $this->optionalString($list['channel_id'] ?? null),
                'active' => (int) $list['active'],
                'item_count' => count($items),
                'item_hash' => $this->hashPayload($items),
            ];
            if (isset($mapping[$key]) && $mapping[$key] !== $candidate) {
                $diffs[] = ['code' => 'b2b_version_mapping_collision', 'map_key' => $key];
            }
            $mapping[$key] = $candidate;
        }
        ksort($mapping, SORT_STRING);

        return [$mapping, $diffs];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function shadowSamples(array $rows): array
    {
        $groups = [];
        foreach ($rows['groups'] as $group) {
            $groups[(string) $group['group_id']] = $group;
        }
        $lists = [];
        foreach ($rows['price_lists'] as $list) {
            $lists[$this->listKey((string) $list['list_id'], (int) $list['version'])] = $list;
        }
        $itemsByList = [];
        foreach ($rows['items'] as $item) {
            $key = $this->listKey((string) $item['list_id'], (int) $item['list_version']);
            $itemsByList[$key][(string) $item['sku']] = (int) $item['amount_minor'];
        }

        $samples = [];
        $diffs = [];
        foreach ($rows['memberships'] as $membership) {
            $groupId = (string) $membership['group_id'];
            $group = $groups[$groupId] ?? null;
            if ($group === null || (string) $group['status'] !== 'active') {
                continue;
            }
            $contexts = [];
            foreach ($lists as $key => $list) {
                if ((string) $list['group_id'] !== $groupId || (int) $list['active'] !== 1) {
                    continue;
                }
                foreach (array_keys($itemsByList[$key] ?? []) as $sku) {
                    $contextKey = ($this->optionalString($list['channel_id'] ?? null) ?? '*')
                        . '#' . $sku;
                    $contexts[$contextKey] = [
                        'channel_id' => $this->optionalString($list['channel_id'] ?? null),
                        'sku' => $sku,
                    ];
                }
            }
            ksort($contexts, SORT_STRING);
            foreach ($contexts as $context) {
                [$selected, $ambiguous] = $this->selectExpectedList(
                    $lists,
                    $itemsByList,
                    $groupId,
                    (int) $membership['website_id'],
                    $context['channel_id'],
                    (string) $context['sku'],
                );
                if ($ambiguous) {
                    $diffs[] = [
                        'code' => 'b2b_price_selection_ambiguous',
                        'customer_id' => $membership['customer_id'],
                        'sku' => $context['sku'],
                        'channel_id' => $context['channel_id'],
                    ];
                    continue;
                }
                if ($selected === null) {
                    continue;
                }
                $listKey = $this->listKey(
                    (string) $selected['list_id'],
                    (int) $selected['version'],
                );
                $amount = (int) $itemsByList[$listKey][(string) $context['sku']];
                $samples[] = [
                    'customer_id' => (string) $membership['customer_id'],
                    'website_id' => (int) $membership['website_id'],
                    'sku' => (string) $context['sku'],
                    'retail_amount_minor' => $amount + 100,
                    'channel_id' => $context['channel_id'],
                    'expected_amount_minor' => $amount,
                    'expected_price_list_id' => (string) $selected['list_id'],
                    'expected_version' => (int) $selected['version'],
                ];
            }
        }
        $this->sortRows($samples);

        return [$samples, $diffs];
    }

    /**
     * @param array<string,array<string,mixed>> $lists
     * @param array<string,array<string,int>> $itemsByList
     * @return array{0:?array<string,mixed>,1:bool}
     */
    private function selectExpectedList(
        array $lists,
        array $itemsByList,
        string $groupId,
        int $websiteId,
        ?string $channelId,
        string $sku,
    ): array {
        $candidates = [];
        foreach ($lists as $key => $list) {
            $listChannel = $this->optionalString($list['channel_id'] ?? null);
            if ((string) $list['group_id'] !== $groupId
                || (int) $list['website_id'] !== $websiteId
                || (int) $list['active'] !== 1
                || !array_key_exists($sku, $itemsByList[$key] ?? [])
                || ($listChannel !== null && $listChannel !== $channelId)
            ) {
                continue;
            }
            $candidates[] = $list + [
                '_scope_priority' => $listChannel !== null ? 1 : 0,
            ];
        }
        usort($candidates, static function (array $left, array $right): int {
            return [
                (int) $right['_scope_priority'],
                (int) $right['version'],
                (string) $right['list_id'],
            ] <=> [
                (int) $left['_scope_priority'],
                (int) $left['version'],
                (string) $left['list_id'],
            ];
        });
        if ($candidates === []) {
            return [null, false];
        }
        $winner = $candidates[0];
        $ambiguous = isset($candidates[1])
            && (int) $candidates[1]['_scope_priority'] === (int) $winner['_scope_priority']
            && (int) $candidates[1]['version'] === (int) $winner['version'];
        unset($winner['_scope_priority']);

        return [$winner, $ambiguous];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @param array<string,array<string,mixed>> $mapping
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>
     */
    private function facts(array $rows, array $mapping, array $samples): array
    {
        $counts = [];
        $hashes = [];
        $canonicalRows = [];
        foreach (self::FACT_FIELDS as $name => $fields) {
            $selected = [];
            foreach ($rows[$name] ?? [] as $row) {
                $fact = [];
                foreach ($fields as $field) {
                    $fact[$field] = $row[$field] ?? null;
                }
                $selected[] = $fact;
            }
            $this->sortRows($selected);
            $canonicalRows[$name] = $selected;
            $counts[$name] = count($selected);
            $hashes[$name] = $this->hashPayload($selected);
        }
        $counts['version_mapping'] = count($mapping);
        $counts['shadow_samples'] = count($samples);
        $hashes['version_mapping'] = $this->hashPayload($mapping);
        $hashes['shadow_samples'] = $this->hashPayload($samples);
        $hashes['combined'] = $this->hashPayload([
            'row_counts' => $counts,
            'row_hashes' => $hashes,
        ]);

        return [
            'schema_fingerprints' => $this->schemaFingerprints(),
            'row_counts' => $counts,
            'row_hashes' => $hashes,
            '_rows' => $canonicalRows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array<string,array<string,mixed>> $mapping
     * @return array<string,mixed>
     */
    private function shadowReport(array $samples, array $mapping): array
    {
        if ($this->forceShadowMismatch && $samples !== []) {
            $samples[0]['expected_amount_minor'] = (int) $samples[0]['expected_amount_minor'] + 1;
        }
        $comparator = B2BShadowComparator::forEngine($this->runtimeEngine());
        $report = $comparator->observe($samples, $mapping);
        $report['report_hash'] = $this->hashPayload($report);

        return $report;
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array{
     *   db:array<string,mixed>,
     *   guard:DatabaseFingerprintGuard,
     *   fingerprint:string,
     *   database:string
     * }
     */
    private function prepareTarget(?array $targetDb): array
    {
        $database = strtolower(trim((string) ($targetDb['database'] ?? '')));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_* created with --mode=full',
            );
        }
        $requested = [
            'type' => (string) ($targetDb['type'] ?? 'pgsql'),
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
        $this->engine = null;

        return [
            'db' => $requested,
            'guard' => $guard,
            'fingerprint' => $fingerprint,
            'database' => $database,
        ];
    }

    private function runtimeRollout(): B2BRolloutGate
    {
        return $this->rolloutGate ??= $this->memoryTarget
            ? B2BRolloutGate::forTestingConfiguration()
            : B2BRolloutGate::forConnection(ConnectionFactory::getInstance());
    }

    private function runtimeEngine(): B2BPriceEngine
    {
        return $this->engine ??= $this->memoryTarget
            ? $this->b2b()->engine()
            : new B2BPriceEngine(
                new CustomerGroupStore(),
                new PriceListStore(),
                $this->runtimeRollout(),
            );
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
                    $facts['_rows']['groups'][0]['website_id'] ?? -1
                ),
                'group_count' => $facts['row_counts']['groups'],
                'membership_count' => $facts['row_counts']['memberships'],
                'version_mapping_count' => $snapshot['mapping_count'],
                'shadow_sample_count' => $snapshot['sample_count'],
                'quote_count' => $facts['row_counts']['quotes'],
                'snapshot_count' => $facts['row_counts']['snapshots'],
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
            'mapping_count' => $snapshot['mapping_count'],
            'diff_count' => count($snapshot['diffs']),
            'diffs' => $snapshot['diffs'],
            'row_counts' => $snapshot['facts']['row_counts'],
            'fact_hash' => $snapshot['facts']['row_hashes']['combined'],
            'version_mapping_hash' => $snapshot['facts']['row_hashes']['version_mapping'],
            'shadow_sample_hash' => $snapshot['facts']['row_hashes']['shadow_samples'],
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

    /**
     * @param class-string<Model> $modelClass
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function fetchScoped(
        string $modelClass,
        string $scopeField,
        int $websiteId,
        array $fields,
    ): array {
        $model = ObjectManager::create($modelClass, [], false);
        $rows = $model->clear()
            ->where($scopeField, $websiteId)
            ->select()
            ->fetchArray();

        return $this->normalizeRows($rows, $fields);
    }

    /**
     * @param class-string<Model> $modelClass
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $modelClass, array $fields): array
    {
        $model = ObjectManager::create($modelClass, [], false);
        return $this->normalizeRows(
            $model->clear()->select()->fetchArray(),
            $fields,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows, array $fields): array
    {
        $intFields = [
            'website_id',
            'group_version',
            'membership_version',
            'version',
            'active',
            'list_version',
            'amount_minor',
            'retail_amount_minor',
            'issued_at_epoch',
            'expires_at_epoch',
            'created_at_epoch',
        ];
        $nullableFields = [
            'channel_id',
            'group_id',
            'price_list_id',
            'version',
            'consumed_order_ref',
        ];
        $normalized = [];
        foreach ($rows as $row) {
            $fact = [];
            foreach ($fields as $field) {
                $sourceField = $field === 'version'
                    && !array_key_exists('version', $row)
                    && array_key_exists('list_version', $row)
                    ? 'list_version'
                    : ($field === 'rule_stack' ? 'rule_stack_json' : $field);
                $value = $row[$sourceField] ?? null;
                if (in_array($field, $nullableFields, true)
                    && ($value === null || $value === '')
                ) {
                    $fact[$field] = null;
                } elseif (in_array($field, $intFields, true)) {
                    $fact[$field] = (int) $value;
                } else {
                    $fact[$field] = $value;
                }
            }
            $normalized[] = $fact;
        }
        $this->sortRows($normalized);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function assertQuote(array $row): void
    {
        new B2BQuoteToken(
            tokenId: (string) $row['token_id'],
            customerId: (string) $row['customer_id'],
            websiteId: (int) $row['website_id'],
            sku: (string) $row['sku'],
            retailAmountMinor: (int) $row['retail_amount_minor'],
            amountMinor: (int) $row['amount_minor'],
            source: (string) $row['source'],
            groupId: $this->optionalString($row['group_id'] ?? null),
            priceListId: $this->optionalString($row['price_list_id'] ?? null),
            version: $row['version'] !== null ? (int) $row['version'] : null,
            channelId: $this->optionalString($row['channel_id'] ?? null),
            ruleStack: array_values((array) ($row['rule_stack'] ?? [])),
            fingerprint: (string) $row['fingerprint'],
            issuedAtEpoch: (int) $row['issued_at_epoch'],
            expiresAtEpoch: (int) $row['expires_at_epoch'],
            status: (string) $row['status'],
            consumedOrderRef: $this->optionalString($row['consumed_order_ref'] ?? null),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function assertSnapshot(array $row): void
    {
        new B2BOrderPriceSnapshot(
            orderRef: (string) $row['order_ref'],
            tokenId: (string) $row['token_id'],
            customerId: (string) $row['customer_id'],
            websiteId: (int) $row['website_id'],
            sku: (string) $row['sku'],
            retailAmountMinor: (int) $row['retail_amount_minor'],
            amountMinor: (int) $row['amount_minor'],
            source: (string) $row['source'],
            groupId: $this->optionalString($row['group_id'] ?? null),
            priceListId: $this->optionalString($row['price_list_id'] ?? null),
            version: $row['version'] !== null ? (int) $row['version'] : null,
            channelId: $this->optionalString($row['channel_id'] ?? null),
            ruleStack: array_values((array) ($row['rule_stack'] ?? [])),
            hash: (string) $row['payload_hash'],
            createdAtEpoch: (int) $row['created_at_epoch'],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,array<string,mixed>> $lists
     * @param list<array<string,mixed>> $diffs
     */
    private function assertVersionReference(
        array $row,
        array $groups,
        array $lists,
        array &$diffs,
        string $kind,
        string $identity,
    ): void {
        $listId = $this->optionalString($row['price_list_id'] ?? null);
        $version = $row['version'] !== null ? (int) $row['version'] : null;
        $groupId = $this->optionalString($row['group_id'] ?? null);
        if ($listId === null && $version === null && $groupId === null) {
            return;
        }
        if ($listId === null || $version === null || $groupId === null) {
            $diffs[] = [
                'code' => 'b2b_' . $kind . '_version_reference_partial',
                'identity' => $identity,
            ];
            return;
        }
        $list = $lists[$this->listKey($listId, $version)] ?? null;
        $group = $groups[$groupId] ?? null;
        if ($list === null
            || $group === null
            || (string) $list['group_id'] !== $groupId
            || (int) $list['website_id'] !== (int) $row['website_id']
        ) {
            $diffs[] = [
                'code' => 'b2b_' . $kind . '_version_reference_mismatch',
                'identity' => $identity,
            ];
        }
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     * @return array<string,mixed>|null
     */
    private function lastEventDetail(array $journal, string $event): ?array
    {
        for ($index = count($journal) - 1; $index >= 0; $index--) {
            if ((string) ($journal[$index]['event'] ?? '') === $event) {
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
                self::ERROR_SCOPE . ': pass --website=N (0 is valid)',
            );
        }
    }

    private function assertMemoryTarget(): void
    {
        if (!$this->memoryTarget) {
            throw new \LogicException('b2b_migration_fixture_is_test_only');
        }
    }

    private function listKey(string $listId, int $version): string
    {
        return $listId . '@v' . $version;
    }

    private function optionalString(mixed $value): ?string
    {
        return $value !== null && trim((string) $value) !== ''
            ? (string) $value
            : null;
    }

    /** @return list<string> */
    private function decodeRules(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @param list<array<string,mixed>> $rows */
    private function sortRows(array &$rows): void
    {
        usort(
            $rows,
            fn (array $left, array $right): int =>
                $this->canonicalJson($left) <=> $this->canonicalJson($right),
        );
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }

            return $value;
        };

        return json_encode(
            $normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
