<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

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
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorPayoutRecord;
use Weline\Vendor\Model\VendorProductBindingRecord;
use Weline\Vendor\Model\VendorRecord;
use Weline\Vendor\Model\VendorRefundReversalRecord;
use Weline\Vendor\Model\VendorSplitRuleRecord;
use Weline\Vendor\Model\VendorSplitSnapshot;
use Weline\Vendor\Model\VendorSplitSnapshotRecord;
use Weline\Vendor\Model\VendorStoreAccountBindingRecord;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;

/**
 * Clone-bound, checkpointed Vendor cutover (TASK-MIG-P4A).
 *
 * Production actions only inspect registry-approved full clones. apply freezes
 * real Vendor ORM facts and stays in shadow; allowlist is a separate action
 * gated by fresh-process verification.
 */
final class VendorMigrationService
{
    public const PHASE = 'p4a-vendor';
    public const CAPABILITY = VendorService::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p4a_vendor_requires_isolated_database';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p4a_vendor_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p4a_vendor_full_clone_required';
    public const ERROR_SCOPE = 'mig_p4a_vendor_scope_required';
    public const ERROR_NO_SAMPLE = 'mig_p4a_vendor_durable_sample_required';
    public const ERROR_INTEGRITY = 'mig_p4a_vendor_fact_integrity_failed';
    public const ERROR_SHADOW_DIFF = 'mig_p4a_vendor_shadow_diff';
    public const ERROR_CHECKPOINT = 'mig_p4a_vendor_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p4a_vendor_checkpoint_fingerprint_mismatch';
    public const ERROR_VERIFY = 'mig_p4a_vendor_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p4a_vendor_allowlist_scope_mismatch';
    public const ERROR_ROLLOUT_MODE = 'mig_p4a_vendor_rollout_mode_invalid';

    /** @var array<string,list<string>> */
    private const FACT_FIELDS = [
        'vendors' => [
            'vendor_id',
            'code',
            'legal_name',
            'environment',
            'status',
            'account_ref',
        ],
        'authorizations' => [
            'vendor_id',
            'website_id',
            'status',
            'grant_version',
        ],
        'accounts' => [
            'vendor_id',
            'website_id',
            'store_id',
            'store_mode_snapshot',
            'environment',
            'account_ref',
            'account_ref_hash',
            'status',
            'binding_version',
        ],
        'products' => [
            'vendor_id',
            'website_id',
            'store_id',
            'product_registry_id',
            'product_sku',
            'global_product_uuid',
            'environment',
            'status',
            'binding_version',
        ],
        'rules' => [
            'vendor_id',
            'website_id',
            'commission_bps',
            'currency',
            'legal_entity',
            'rule_version',
            'cas_token',
        ],
        'snapshots' => [
            'snapshot_id',
            'schema_version',
            'vendor_id',
            'website_id',
            'store_id',
            'store_mode_snapshot',
            'environment',
            'checkout_group_ref',
            'order_ref',
            'payment_ref',
            'currency',
            'gross_minor',
            'vendor_share_minor',
            'platform_share_minor',
            'commission_bps',
            'legal_json',
            'account_json',
            'commission_json',
            'payload_hash',
        ],
        'payouts' => [
            'payout_id',
            'snapshot_id',
            'vendor_id',
            'website_id',
            'store_id',
            'store_mode_snapshot',
            'environment',
            'currency',
            'amount_minor',
            'reversed_minor',
            'net_minor',
            'status',
            'account_ref',
            'legal_entity',
            'idempotency_key',
            'request_hash',
            'ledger_version',
            'cas_token',
        ],
        'reversals' => [
            'reversal_id',
            'payout_id',
            'snapshot_id',
            'vendor_id',
            'website_id',
            'store_id',
            'store_mode_snapshot',
            'environment',
            'refund_ref',
            'amount_minor',
            'currency',
            'reason',
            'payout_net_after_minor',
            'request_hash',
        ],
    ];

    /** @var array<string,class-string<Model>> */
    private const FACT_MODELS = [
        'vendors' => VendorRecord::class,
        'authorizations' => VendorWebsiteAuthorizationRecord::class,
        'accounts' => VendorStoreAccountBindingRecord::class,
        'products' => VendorProductBindingRecord::class,
        'rules' => VendorSplitRuleRecord::class,
        'snapshots' => VendorSplitSnapshotRecord::class,
        'payouts' => VendorPayoutRecord::class,
        'reversals' => VendorRefundReversalRecord::class,
    ];

    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;
    private ?MigrationTargetBinder $targetBinder = null;
    private ?VendorRolloutGate $rolloutGate = null;
    private bool $memoryTarget = false;
    private bool $forceShadowMismatch = false;

    /** @var array<string,list<array<string,mixed>>> */
    private array $memoryRows = [
        'vendors' => [],
        'authorizations' => [],
        'accounts' => [],
        'products' => [],
        'rules' => [],
        'snapshots' => [],
        'payouts' => [],
        'reversals' => [],
    ];

    public function __construct()
    {
    }

    public static function forTesting(?string $journalDir = null): self
    {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? sys_get_temp_dir() . '/mig_p4avendor_' . uniqid('', true),
        );
        $service = new self();
        $service->fingerprintGuard = $guard;
        $service->checkpointService = new MigrationCheckpointService($guard, $store);
        $service->rolloutGate = VendorRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
        ]);
        $service->memoryTarget = true;

        return $service;
    }

    public function rollout(): VendorRolloutGate
    {
        return $this->runtimeRollout();
    }

    /**
     * Test-only source fixture. Production preflight always reads ORM rows.
     *
     * @param array<string,mixed> $binding
     */
    public function seedBinding(array $binding): void
    {
        $this->assertMemoryTarget();
        $this->ensureMemoryBase();
        $sku = trim((string) ($binding['product_sku'] ?? ''));
        if ($sku === '') {
            throw new \InvalidArgumentException('vendor_migration_test_sku_required');
        }
        foreach ($this->memoryRows['products'] as $row) {
            if ((string) $row['product_sku'] === $sku) {
                return;
            }
        }
        $index = count($this->memoryRows['products']) + 1;
        $this->memoryRows['products'][] = [
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'store_id' => 901,
            'product_registry_id' => $index,
            'product_sku' => $sku,
            'global_product_uuid' => sprintf('30000000-0000-4000-8000-%012d', $index),
            'environment' => VendorIdentity::ENV_SANDBOX,
            'status' => 'bound',
            'binding_version' => 1,
        ];
    }

    /**
     * Test-only payable fixture. Production preflight always reads ORM rows.
     *
     * @param array<string,mixed> $payable
     */
    public function seedPayable(array $payable): void
    {
        $this->assertMemoryTarget();
        $this->ensureMemoryBase();
        $index = count($this->memoryRows['snapshots']) + 1;
        $orderRef = trim((string) ($payable['order_ref'] ?? ('ord-mig-' . $index)));
        $paymentRef = trim((string) ($payable['payment_ref'] ?? ('pay-mig-' . $index)));
        $gross = (int) ($payable['gross_minor'] ?? 0);
        if ($gross <= 0) {
            throw new \InvalidArgumentException('vendor_migration_test_gross_required');
        }
        $platform = intdiv($gross * 1000, 10000);
        $vendorShare = $gross - $platform;
        $snapshotId = 'vss_mig_' . substr(
            hash('sha256', $orderRef . '|' . $paymentRef),
            0,
            20,
        );
        $payload = [
            'snapshot_id' => $snapshotId,
            'schema_version' => VendorSplitSnapshot::SCHEMA_VERSION,
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'store_id' => 901,
            'store_mode_snapshot' => 'test',
            'environment' => VendorIdentity::ENV_SANDBOX,
            'checkout_group_ref' => 'grp-mig-p4a',
            'order_ref' => $orderRef,
            'payment_ref' => $paymentRef,
            'currency' => 'CNY',
            'gross_minor' => $gross,
            'vendor_share_minor' => $vendorShare,
            'platform_share_minor' => $platform,
            'commission_bps' => 1000,
            'legal_json' => '{"legal_entity":"MIG P4A Legal"}',
            'account_json' => '{"account_ref":"sandbox:mig_p4a_vendor"}',
            'commission_json' => '{"commission_bps":1000}',
        ];
        $payload['payload_hash'] = $this->hashPayload($payload);
        $this->memoryRows['snapshots'][] = $payload;
        $this->memoryRows['payouts'][] = [
            'payout_id' => 'po_' . substr(hash('sha256', $snapshotId), 0, 24),
            'snapshot_id' => $snapshotId,
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'store_id' => 901,
            'store_mode_snapshot' => 'test',
            'environment' => VendorIdentity::ENV_SANDBOX,
            'currency' => 'CNY',
            'amount_minor' => $vendorShare,
            'reversed_minor' => 0,
            'net_minor' => $vendorShare,
            'status' => VendorPayoutLedger::STATUS_SCHEDULED,
            'account_ref' => 'sandbox:mig_p4a_vendor',
            'legal_entity' => 'MIG P4A Legal',
            'idempotency_key' => 'mig-' . $index,
            'request_hash' => hash('sha256', $snapshotId . '|mig-' . $index),
            'ledger_version' => 1,
            'cas_token' => str_repeat((string) (($index % 9) + 1), 64),
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
    public function preflight(
        ?array $targetDb,
        int $websiteId,
        int $storeId,
    ): array {
        $this->assertScope($websiteId, $storeId);
        $target = $this->prepareTarget($targetDb);
        $facts = $this->inspectFacts($target, $websiteId, $storeId);

        return $this->publicFacts($facts) + [
            'phase' => self::PHASE,
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'mode' => $this->runtimeRollout()->mode(self::CAPABILITY),
            'shared_db_apply_forbidden' => true,
            'full_clone_required' => true,
        ];
    }

    /**
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function apply(
        ?array $targetDb,
        int $websiteId,
        int $storeId,
    ): array {
        $this->assertScope($websiteId, $storeId);
        $target = $this->prepareTarget($targetDb);
        $facts = $this->inspectFacts($target, $websiteId, $storeId);
        if (empty($facts['apply_ready'])) {
            return $this->publicFacts($facts) + [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'fingerprint' => $target['fingerprint'],
                'error' => empty($facts['sample_count'])
                    ? self::ERROR_NO_SAMPLE
                    : self::ERROR_INTEGRITY,
            ];
        }

        $rollout = $this->runtimeRollout();
        $modeBefore = $rollout->mode(self::CAPABILITY);
        if (!in_array(
            $modeBefore,
            [CommerceRolloutGateInterface::MODE_OFF, CommerceRolloutGateInterface::MODE_SHADOW],
            true,
        )) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'error' => self::ERROR_ROLLOUT_MODE,
                'mode' => $modeBefore,
            ];
        }

        $checkpointId = 'p4avendor-' . gmdate('YmdHis') . '-' . substr(
            bin2hex(random_bytes(3)),
            0,
            6,
        );
        $manifest = $this->manifest($checkpointId, $target, $facts);
        $checkpoint = $this->checkpoint($target['guard']);
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p4a_vendor_preflight_snapshot', [
            'database' => $target['database'],
            'scope' => ['website_id' => $websiteId, 'store_id' => $storeId],
            'mode_before' => $modeBefore,
            'row_counts' => $facts['row_counts'],
            'row_hashes' => $facts['row_hashes'],
            'schema_fingerprints' => $facts['schema_fingerprints'],
            'report' => $facts['report'],
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $report = (new VendorShadowComparator())->compare(
            $facts['_rows']['snapshots'],
            $facts['_rows']['payouts'],
            $facts['_rows']['reversals'],
            $this->forceShadowMismatch,
        );
        if (empty($report['ok'])) {
            $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
            $checkpoint->appendJournal($checkpointId, 'p4a_vendor_shadow_rejected', [
                'database' => $target['database'],
                'report' => $report,
                'mode' => CommerceRolloutGateInterface::MODE_OFF,
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
                'business_rows_written' => 0,
            ];
        }

        $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        $configuration = $rollout->configuration();
        if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4a_vendor_shadow_readback_failed');
        }
        $checkpoint->appendJournal($checkpointId, 'p4a_vendor_shadow_applied', [
            'database' => $target['database'],
            'scope' => ['website_id' => $websiteId, 'store_id' => $storeId],
            'fact_hash' => $facts['row_hashes']['combined'],
            'report' => $report,
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'business_rows_written' => 0,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'fresh_verify_required' => true,
            'business_rows_written' => 0,
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'report' => $report,
            'snapshots_immutable' => true,
            'settlement_facts_retained' => true,
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
            $diffs[] = ['code' => 'mig_p4a_vendor_checkpoint_phase_mismatch'];
        }
        if (!hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            $diffs[] = ['code' => self::ERROR_FINGERPRINT];
        }
        $websiteId = (int) ($manifest->watermarks['website_id'] ?? -1);
        $storeId = (int) ($manifest->watermarks['store_id'] ?? 0);
        try {
            $this->assertScope($websiteId, $storeId);
        } catch (\Throwable) {
            $diffs[] = ['code' => self::ERROR_SCOPE];
        }

        $facts = $this->inspectFacts($target, $websiteId, $storeId);
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
        if (empty($facts['apply_ready'])) {
            $diffs[] = ['code' => self::ERROR_INTEGRITY];
        }

        $preflightEvent = $this->lastEventDetail(
            $stored['journal'],
            'p4a_vendor_preflight_snapshot',
        );
        $applyEvent = $this->lastEventDetail(
            $stored['journal'],
            'p4a_vendor_shadow_applied',
        );
        if ($preflightEvent === null) {
            $diffs[] = ['code' => 'mig_p4a_vendor_preflight_journal_missing'];
        }
        if ($applyEvent === null) {
            $diffs[] = ['code' => 'mig_p4a_vendor_apply_journal_missing'];
        } elseif (!hash_equals(
            (string) ($applyEvent['report']['report_hash'] ?? ''),
            (string) ($facts['report']['report_hash'] ?? ''),
        )) {
            $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
        }

        $configuration = $this->runtimeRollout()->configuration();
        if (!in_array(
            $configuration['mode'],
            [
                CommerceRolloutGateInterface::MODE_SHADOW,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                CommerceRolloutGateInterface::MODE_OFF,
            ],
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
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $configuration['mode'],
            'allowlist' => $configuration['allowlist_rows'],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'report' => $facts['report'],
            'fresh_journal' => $fresh,
            'snapshots_immutable' => true,
            'settlement_facts_retained' => true,
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
    ): array {
        $this->assertScope($websiteId, $storeId);
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
        if ((int) $verified['website_id'] !== $websiteId
            || (int) $verified['store_id'] !== $storeId
        ) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_SCOPE_MISMATCH,
                'expected_scope' => [
                    'website_id' => $verified['website_id'],
                    'store_id' => $verified['store_id'],
                ],
                'requested_scope' => [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                ],
            ];
        }
        if (!in_array(
            (string) $verified['mode'],
            [CommerceRolloutGateInterface::MODE_SHADOW, CommerceRolloutGateInterface::MODE_ALLOWLIST],
            true,
        )) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_ROLLOUT_MODE,
                'mode' => $verified['mode'],
            ];
        }

        $target = $this->prepareTarget($targetDb);
        $rollout = $this->runtimeRollout();
        $subject = VendorRolloutGate::scopeKey($websiteId, $storeId);
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
            throw new \RuntimeException('mig_p4a_vendor_allowlist_readback_failed');
        }
        $checkpoint = $this->checkpoint($target['guard']);
        $checkpoint->appendJournal($checkpointId, 'p4a_vendor_allowlist_applied', [
            'database' => $target['database'],
            'scope' => ['website_id' => $websiteId, 'store_id' => $storeId],
            'subject' => $subject,
            'verified_manifest_hash' => $verified['manifest_hash'],
            'replayed' => $before['mode'] === CommerceRolloutGateInterface::MODE_ALLOWLIST
                && array_keys($before['allowlist']) === [$subject],
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => $readback['mode'],
            'allowlist' => $readback['allowlist_rows'],
            'fresh_verify' => true,
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
        if (!hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }

        $before = $this->runtimeRollout()->configuration();
        $checkpoint->rollbackGuard($checkpointId);
        $this->runtimeRollout()->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );
        $readback = $this->runtimeRollout()->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $readback['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4a_vendor_rollback_readback_failed');
        }
        $facts = $this->inspectFacts(
            $target,
            (int) $verified['website_id'],
            (int) $verified['store_id'],
        );
        if (!hash_equals(
            (string) $verified['fact_hash'],
            (string) $facts['row_hashes']['combined'],
        )) {
            throw new \RuntimeException('mig_p4a_vendor_rollback_changed_facts');
        }
        $checkpoint->appendJournal($checkpointId, 'p4a_vendor_mode_off', [
            'database' => $target['database'],
            'scope' => [
                'website_id' => $verified['website_id'],
                'store_id' => $verified['store_id'],
            ],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'snapshots_immutable' => true,
            'settlement_facts_retained' => true,
            'continue_existing_settlement' => true,
            'replayed' => $before['mode'] === CommerceRolloutGateInterface::MODE_OFF
                && $before['allowlist'] === [],
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'snapshots_immutable' => true,
            'settlement_facts_retained' => true,
            'bindings_retained' => true,
            'continue_existing_settlement' => true,
            'new_split_blocked' => true,
            'checkpoint_retained' => true,
        ];
    }

    /**
     * Resolve a fresh ORM settlement facade after clone binding. This exists for
     * executable migration acceptance; the migration service itself never
     * fabricates production Vendor business rows.
     *
     * @param array<string,mixed>|null $targetDb
     */
    public function settlementForTarget(?array $targetDb): VendorSettlementService
    {
        if ($this->memoryTarget) {
            throw new \LogicException('vendor_migration_memory_target_has_no_runtime_orm');
        }
        $this->prepareTarget($targetDb);

        return VendorSettlementService::forRuntime($this->runtimeRollout());
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function inspectFacts(array $target, int $websiteId, int $storeId): array
    {
        $rows = $this->memoryTarget
            ? $this->memoryRows
            : $this->loadOrmRows();
        $accountRows = array_values(array_filter(
            $rows['accounts'],
            static fn (array $row): bool => (int) ($row['website_id'] ?? -1) === $websiteId
                && (int) ($row['store_id'] ?? 0) === $storeId,
        ));
        $vendorIds = [];
        foreach ($accountRows as $row) {
            $vendorId = trim((string) ($row['vendor_id'] ?? ''));
            if ($vendorId !== '') {
                $vendorIds[$vendorId] = true;
            }
        }
        $withinVendor = static fn (array $row): bool
            => isset($vendorIds[(string) ($row['vendor_id'] ?? '')]);
        $withinWebsite = static fn (array $row): bool
            => (int) ($row['website_id'] ?? -1) === $websiteId;
        $withinStore = static fn (array $row): bool
            => (int) ($row['store_id'] ?? 0) === $storeId;

        $scoped = [
            'vendors' => array_values(array_filter($rows['vendors'], $withinVendor)),
            'authorizations' => array_values(array_filter(
                $rows['authorizations'],
                static fn (array $row): bool => $withinVendor($row) && $withinWebsite($row),
            )),
            'accounts' => $accountRows,
            'products' => array_values(array_filter(
                $rows['products'],
                static fn (array $row): bool
                    => $withinVendor($row) && $withinWebsite($row) && $withinStore($row),
            )),
            'rules' => array_values(array_filter(
                $rows['rules'],
                static fn (array $row): bool => $withinVendor($row) && $withinWebsite($row),
            )),
            'snapshots' => array_values(array_filter(
                $rows['snapshots'],
                static fn (array $row): bool
                    => $withinVendor($row) && $withinWebsite($row) && $withinStore($row),
            )),
            'payouts' => array_values(array_filter(
                $rows['payouts'],
                static fn (array $row): bool
                    => $withinVendor($row) && $withinWebsite($row) && $withinStore($row),
            )),
            'reversals' => array_values(array_filter(
                $rows['reversals'],
                static fn (array $row): bool
                    => $withinVendor($row) && $withinWebsite($row) && $withinStore($row),
            )),
        ];
        foreach ($scoped as $name => $factRows) {
            $scoped[$name] = $this->projectRows($factRows, self::FACT_FIELDS[$name]);
        }

        $rowCounts = [];
        $rowHashes = [];
        foreach ($scoped as $name => $factRows) {
            $rowCounts[$name] = count($factRows);
            $rowHashes[$name] = $this->hashPayload($factRows);
        }
        $rowHashes['combined'] = $this->hashPayload($rowHashes);
        $report = (new VendorShadowComparator())->compare(
            $scoped['snapshots'],
            $scoped['payouts'],
            $scoped['reversals'],
        );
        $diffs = $this->integrityDiffs($scoped, $report);

        return [
            'ok' => $diffs === [],
            'apply_ready' => $diffs === [],
            'sample_count' => count($scoped['snapshots']),
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'row_counts' => $rowCounts,
            'row_hashes' => $rowHashes,
            'schema_fingerprints' => $this->schemaFingerprints(),
            'watermarks' => [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'grant_version' => $this->maxInt($scoped['authorizations'], 'grant_version'),
                'account_binding_version' => $this->maxInt($scoped['accounts'], 'binding_version'),
                'product_binding_version' => $this->maxInt($scoped['products'], 'binding_version'),
                'rule_version' => $this->maxInt($scoped['rules'], 'rule_version'),
                'ledger_version' => $this->maxInt($scoped['payouts'], 'ledger_version'),
            ],
            'report' => $report,
            'normal_test_isolation' => true,
            'business_rows_written' => 0,
            '_rows' => $scoped,
            '_target' => $target,
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @param array<string,mixed> $report
     * @return list<array<string,mixed>>
     */
    private function integrityDiffs(array $rows, array $report): array
    {
        $diffs = [];
        foreach (['vendors', 'authorizations', 'accounts', 'products', 'rules', 'snapshots', 'payouts'] as $name) {
            if ($rows[$name] === []) {
                $diffs[] = ['code' => 'vendor_migration_' . $name . '_required'];
            }
        }
        foreach ($rows['vendors'] as $row) {
            if ((string) $row['status'] !== VendorIdentity::STATUS_ACTIVE
                || (string) $row['environment'] !== VendorIdentity::ENV_SANDBOX
            ) {
                $diffs[] = ['code' => 'vendor_migration_vendor_not_sandbox_active'];
            }
        }
        foreach ($rows['authorizations'] as $row) {
            if ((string) $row['status'] !== VendorAuthorizationService::STATUS_AUTHORIZED) {
                $diffs[] = ['code' => 'vendor_migration_authorization_not_active'];
            }
        }
        foreach ($rows['accounts'] as $row) {
            $ref = (string) $row['account_ref'];
            if ((string) $row['status'] !== VendorStoreAccountBindingService::STATUS_BOUND
                || (string) $row['environment'] !== VendorIdentity::ENV_SANDBOX
                || !in_array((string) $row['store_mode_snapshot'], ['dev', 'test'], true)
                || !str_starts_with($ref, 'sandbox:')
                || !hash_equals((string) $row['account_ref_hash'], hash('sha256', $ref))
            ) {
                $diffs[] = ['code' => 'vendor_migration_account_scope_invalid'];
            }
        }
        foreach ($rows['products'] as $row) {
            if ((string) $row['status'] !== 'bound'
                || (string) $row['environment'] !== VendorIdentity::ENV_SANDBOX
                || (int) $row['product_registry_id'] <= 0
            ) {
                $diffs[] = ['code' => 'vendor_migration_product_binding_invalid'];
            }
        }
        foreach ($rows['rules'] as $row) {
            $bps = (int) $row['commission_bps'];
            if ($bps < 0 || $bps > 10000 || (int) $row['rule_version'] < 1) {
                $diffs[] = ['code' => 'vendor_migration_rule_invalid'];
            }
        }
        foreach ($rows['snapshots'] as $row) {
            if ((string) $row['schema_version'] !== VendorSplitSnapshot::SCHEMA_VERSION
                || (string) $row['environment'] !== VendorIdentity::ENV_SANDBOX
                || !in_array((string) $row['store_mode_snapshot'], ['dev', 'test'], true)
                || strlen((string) $row['payload_hash']) !== 64
            ) {
                $diffs[] = ['code' => 'vendor_migration_snapshot_invalid'];
            }
        }
        if (empty($report['ok'])) {
            $diffs[] = [
                'code' => self::ERROR_SHADOW_DIFF,
                'report_hash' => $report['report_hash'] ?? '',
            ];
        }

        $coverage = [];
        foreach (array_keys($rows) as $name) {
            if ($name === 'reversals') {
                continue;
            }
            foreach ($rows[$name] as $row) {
                if (isset($row['vendor_id'])) {
                    $coverage[$name][(string) $row['vendor_id']] = true;
                }
            }
        }
        foreach ($rows['vendors'] as $vendor) {
            $vendorId = (string) $vendor['vendor_id'];
            foreach (['authorizations', 'accounts', 'products', 'rules', 'snapshots', 'payouts'] as $name) {
                if (!isset($coverage[$name][$vendorId])) {
                    $diffs[] = [
                        'code' => 'vendor_migration_vendor_coverage_missing',
                        'vendor_id' => $vendorId,
                        'fact' => $name,
                    ];
                }
            }
        }

        return $diffs;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadOrmRows(): array
    {
        $rows = [];
        foreach (self::FACT_MODELS as $name => $modelClass) {
            $model = ObjectManager::create($modelClass, [], false);
            if (!$model instanceof Model) {
                throw new \LogicException('vendor_migration_model_unavailable:' . $modelClass);
            }
            $rows[$name] = array_values($model->clear()->select()->fetchArray());
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $rows, array $fields): array
    {
        $projected = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($fields as $field) {
                $item[$field] = $row[$field] ?? null;
            }
            $projected[] = $item;
        }
        usort(
            $projected,
            fn (array $left, array $right): int
                => strcmp($this->canonicalJson($left), $this->canonicalJson($right)),
        );

        return $projected;
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
            $fingerprint = $guard->assertIsolatedDatabase($requested);
            return [
                'db' => $requested,
                'guard' => $guard,
                'fingerprint' => $fingerprint,
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
        // Re-resolve DB-backed collaborators after every target bind.
        $this->rolloutGate = null;
        $this->checkpointService = null;

        return [
            'db' => $requested,
            'guard' => $guard,
            'fingerprint' => $fingerprint,
            'database' => $database,
        ];
    }

    private function runtimeRollout(): VendorRolloutGate
    {
        return $this->rolloutGate ??= VendorRolloutGate::forConnection(
            ConnectionFactory::getInstance(),
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
     * @param array<string,mixed> $facts
     */
    private function manifest(
        string $checkpointId,
        array $target,
        array $facts,
    ): MigrationManifest {
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
            'watermarks' => $facts['watermarks'],
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
                $name === 'snapshots' ? VendorSplitSnapshot::SCHEMA_VERSION : '',
            ]));
        }

        return $out;
    }

    private function requireCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId === '') {
            throw new \InvalidArgumentException(self::ERROR_CHECKPOINT);
        }

        return $checkpointId;
    }

    private function assertScope(int $websiteId, int $storeId): void
    {
        VendorIdentity::assertWebsiteId($websiteId);
        if ($storeId <= 0) {
            throw new \InvalidArgumentException(self::ERROR_SCOPE);
        }
    }

    private function assertMemoryTarget(): void
    {
        if (!$this->memoryTarget) {
            throw new \LogicException('vendor_migration_seed_is_test_only');
        }
    }

    private function ensureMemoryBase(): void
    {
        if ($this->memoryRows['vendors'] !== []) {
            return;
        }
        $this->memoryRows['vendors'][] = [
            'vendor_id' => 'vnd_mig_p4a',
            'code' => 'mig_p4a_vendor',
            'legal_name' => 'MIG P4A Vendor',
            'environment' => VendorIdentity::ENV_SANDBOX,
            'status' => VendorIdentity::STATUS_ACTIVE,
            'account_ref' => 'sandbox:mig_p4a_vendor',
        ];
        $this->memoryRows['authorizations'][] = [
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'status' => VendorAuthorizationService::STATUS_AUTHORIZED,
            'grant_version' => 1,
        ];
        $this->memoryRows['accounts'][] = [
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'store_id' => 901,
            'store_mode_snapshot' => 'test',
            'environment' => VendorIdentity::ENV_SANDBOX,
            'account_ref' => 'sandbox:mig_p4a_vendor',
            'account_ref_hash' => hash('sha256', 'sandbox:mig_p4a_vendor'),
            'status' => VendorStoreAccountBindingService::STATUS_BOUND,
            'binding_version' => 1,
        ];
        $this->memoryRows['rules'][] = [
            'vendor_id' => 'vnd_mig_p4a',
            'website_id' => 0,
            'commission_bps' => 1000,
            'currency' => 'CNY',
            'legal_entity' => 'MIG P4A Legal',
            'rule_version' => 1,
            'cas_token' => str_repeat('a', 64),
        ];
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     * @return array<string,mixed>|null
     */
    private function lastEventDetail(array $journal, string $event): ?array
    {
        for ($index = count($journal) - 1; $index >= 0; $index--) {
            if ((string) ($journal[$index]['event'] ?? '') === $event) {
                $detail = $journal[$index]['detail'] ?? null;
                return is_array($detail) ? $detail : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function maxInt(array $rows, string $field): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row[$field] ?? 0));
        }

        return $max;
    }

    /** @return array<string,mixed> */
    private function publicFacts(array $facts): array
    {
        $out = $facts;
        unset($out['_rows'], $out['_target']);

        return $out;
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $payload): string
    {
        $normalized = $this->normalize($payload);

        return json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
