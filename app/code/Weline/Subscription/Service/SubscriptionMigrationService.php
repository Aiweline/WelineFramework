<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\Subscription;
use Weline\Subscription\Model\SubscriptionBillingAttempt;
use Weline\Subscription\Model\SubscriptionMissedWatermark;
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionSchedulerLease;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Clone-bound, checkpointed Subscription Period/watermark cutover.
 */
final class SubscriptionMigrationService
{
    public const PHASE = 'p4b-subscription';
    public const CAPABILITY = SubscriptionService::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p4b_subscription_requires_isolated_database';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p4b_subscription_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p4b_subscription_full_clone_required';
    public const ERROR_SCOPE = 'mig_p4b_subscription_website_required';
    public const ERROR_NO_SAMPLE = 'mig_p4b_subscription_durable_sample_required';
    public const ERROR_INTEGRITY = 'mig_p4b_subscription_fact_integrity_failed';
    public const ERROR_SHADOW_DIFF = 'mig_p4b_subscription_shadow_diff';
    public const ERROR_CHECKPOINT = 'mig_p4b_subscription_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p4b_subscription_checkpoint_fingerprint_mismatch';
    public const ERROR_VERIFY = 'mig_p4b_subscription_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p4b_subscription_allowlist_scope_mismatch';
    public const ERROR_ROLLOUT_MODE = 'mig_p4b_subscription_rollout_mode_invalid';
    public const ERROR_ACTIVE_LEASE = 'mig_p4b_subscription_active_lease';
    public const BACKFILL_REASON = 'migration_backfill_gap';

    /** @var array<string,list<string>> */
    private const FACT_FIELDS = [
        'subscriptions' => [
            'subscription_id',
            'customer_id',
            'website_id',
            'store_id',
            'provider_code',
            'plan_code',
            'environment',
            'status',
            'current_period_index',
        ],
        'periods' => [
            'period_key',
            'subscription_id',
            'period_index',
            'website_id',
            'status',
            'order_ref',
            'missed_reason',
        ],
        'attempts' => [
            'attempt_id',
            'period_key',
            'subscription_id',
            'attempt_no',
            'status',
            'active_guard',
            'order_ref',
            'payment_intent_code',
            'payment_attempt_code',
            'payment_status',
            'error_code',
        ],
        'watermarks' => [
            'subscription_id',
            'period_index',
            'period_key',
            'reason',
        ],
        'leases' => [
            'subscription_id',
            'worker_id',
            'expires_at_epoch',
        ],
    ];

    /** @var array<string,list<string>> */
    private const INT_FIELDS = [
        'subscriptions' => ['website_id', 'store_id', 'current_period_index'],
        'periods' => ['period_index', 'website_id'],
        'attempts' => ['attempt_no'],
        'watermarks' => ['period_index'],
        'leases' => ['expires_at_epoch'],
    ];

    /** @var array<string,class-string<Model>> */
    private const FACT_MODELS = [
        'subscriptions' => Subscription::class,
        'periods' => SubscriptionPeriod::class,
        'attempts' => SubscriptionBillingAttempt::class,
        'watermarks' => SubscriptionMissedWatermark::class,
        'leases' => SubscriptionSchedulerLease::class,
    ];

    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;
    private ?MigrationTargetBinder $targetBinder = null;
    private ?SubscriptionRolloutGate $rolloutGate = null;
    private ?SubscriptionSchedulerService $memoryScheduler = null;
    private bool $memoryTarget = false;
    private bool $forceShadowMismatch = false;

    /** @var list<string> */
    private array $memorySubscriptionIds = [];

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
                $journalDir ?? sys_get_temp_dir() . '/mig_p4bsub_' . uniqid('', true),
            ),
        );
        $service->rolloutGate = SubscriptionRolloutGate::forTestingConfiguration();
        $subscriptions = SubscriptionService::forTesting($service->rolloutGate);
        $service->memoryScheduler = SubscriptionSchedulerService::forTesting(
            $subscriptions,
            ArraySubscriptionOrderPort::forTesting(),
            $service->rolloutGate,
            ArraySubscriptionPaymentPort::forTesting(),
        );

        return $service;
    }

    public function scheduler(): SubscriptionSchedulerService
    {
        if ($this->memoryScheduler === null) {
            throw new \LogicException('subscription_migration_scheduler_is_test_only');
        }

        return $this->memoryScheduler;
    }

    public function rollout(): SubscriptionRolloutGate
    {
        return $this->runtimeRollout();
    }

    /**
     * Test-only source fixture. Production preflight always reads clone ORM rows.
     *
     * `periods_to_bill` is retained as a compatibility fixture name and means
     * the highest due period index. Period 1 exists from create; higher indices
     * are deliberate gaps for migration backfill.
     *
     * @param array{
     *   customer_id:string,
     *   plan_code:string,
     *   periods_to_bill?:int,
     *   website_id?:int,
     *   store_id?:int
     * } $seed
     */
    public function seedSubscription(array $seed): string
    {
        $this->assertMemoryTarget();
        $websiteId = (int) ($seed['website_id'] ?? 0);
        $dueIndex = max(1, (int) ($seed['periods_to_bill'] ?? 1));
        $subject = SubscriptionRolloutGate::scopeKey($websiteId);
        $gate = $this->runtimeRollout();
        $gate->setMode(self::CAPABILITY, SubscriptionRolloutGate::MODE_ALLOWLIST, [$subject]);
        try {
            $created = $this->scheduler()->subscriptions()->create([
                'customer_id' => (string) ($seed['customer_id'] ?? ''),
                'website_id' => $websiteId,
                'store_id' => max(0, (int) ($seed['store_id'] ?? 0)),
                'provider_code' => 'interval_monthly',
                'plan_code' => (string) ($seed['plan_code'] ?? ''),
                'idempotency_key' => 'mig-p4b-fixture-' . count($this->memorySubscriptionIds),
                'environment' => 'sandbox',
            ]);
            if ($dueIndex > (int) $created['current_period_index']) {
                $created = $this->scheduler()->subscriptions()->store()->replaceWithVersionBump(
                    (string) $created['subscription_id'],
                    (int) $created['version'],
                    ['current_period_index' => $dueIndex],
                );
            }
            $this->memorySubscriptionIds[] = (string) $created['subscription_id'];
        } finally {
            $gate->setMode(self::CAPABILITY, SubscriptionRolloutGate::MODE_OFF);
        }

        return (string) $created['subscription_id'];
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
        if (empty($snapshot['apply_ready'])) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'fingerprint' => $target['fingerprint'],
                'error' => $snapshot['sample_count'] === 0
                    ? self::ERROR_NO_SAMPLE
                    : ($snapshot['active_lease_count'] > 0
                        ? self::ERROR_ACTIVE_LEASE
                        : self::ERROR_INTEGRITY),
            ] + $this->publicSnapshot($snapshot);
        }

        $rollout = $this->runtimeRollout();
        $modeBefore = $rollout->mode(self::CAPABILITY);
        if (!in_array($modeBefore, [
            SubscriptionRolloutGate::MODE_OFF,
            SubscriptionRolloutGate::MODE_SHADOW,
        ], true)) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'database' => $target['database'],
                'error' => self::ERROR_ROLLOUT_MODE,
                'mode' => $modeBefore,
            ];
        }

        $checkpointId = 'p4bsub-' . gmdate('YmdHis') . '-' . substr(
            bin2hex(random_bytes(3)),
            0,
            6,
        );
        $manifest = $this->manifest(
            $checkpointId,
            $target,
            $snapshot['expected_facts'],
            $snapshot,
        );
        $checkpoint = $this->checkpoint($target['guard']);
        // The immutable expected post-backfill snapshot exists before any
        // rollout, Period or watermark write.
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p4b_subscription_preflight_snapshot', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'mode_before' => $modeBefore,
            'current_row_counts' => $snapshot['current_facts']['row_counts'],
            'current_row_hashes' => $snapshot['current_facts']['row_hashes'],
            'expected_row_counts' => $snapshot['expected_facts']['row_counts'],
            'expected_row_hashes' => $snapshot['expected_facts']['row_hashes'],
            'gap_period_count' => count($snapshot['plan']['periods']),
            'watermark_event_count' => count($snapshot['plan']['watermarks']),
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $rollout->setMode(self::CAPABILITY, SubscriptionRolloutGate::MODE_SHADOW);
        $backfill = $this->executeBackfill($snapshot['plan']);
        $after = $this->snapshot($websiteId);
        $report = $after['current_facts']['report'];
        if ($this->forceShadowMismatch) {
            $report = $this->forcedMismatch($report);
        }
        $factMatch = $this->sameFacts(
            $snapshot['expected_facts'],
            $after['current_facts'],
        );
        if (!$factMatch || empty($report['ok']) || $after['active_lease_count'] > 0) {
            $rollout->setMode(self::CAPABILITY, SubscriptionRolloutGate::MODE_OFF);
            $checkpoint->appendJournal($checkpointId, 'p4b_subscription_shadow_rejected', [
                'database' => $target['database'],
                'fact_match' => $factMatch,
                'report' => $report,
                'active_lease_count' => $after['active_lease_count'],
                'mode' => SubscriptionRolloutGate::MODE_OFF,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => $target['database'],
                'fingerprint' => $target['fingerprint'],
                'error' => self::ERROR_SHADOW_DIFF,
                'mode' => SubscriptionRolloutGate::MODE_OFF,
                'report' => $report,
                'backfill' => $backfill,
            ];
        }

        $configuration = $rollout->configuration();
        if ($configuration['mode'] !== SubscriptionRolloutGate::MODE_SHADOW
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4b_subscription_shadow_readback_failed');
        }
        $checkpoint->appendJournal($checkpointId, 'p4b_subscription_shadow_applied', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $after['current_facts']['row_hashes']['combined'],
            'report' => $report,
            'backfill' => $backfill,
            'mode' => SubscriptionRolloutGate::MODE_SHADOW,
            'external_order_writes' => 0,
            'external_payment_writes' => 0,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'website_id' => $websiteId,
            'mode' => SubscriptionRolloutGate::MODE_SHADOW,
            'allowlist' => [],
            'fresh_verify_required' => true,
            'fact_hash' => $after['current_facts']['row_hashes']['combined'],
            'row_counts' => $after['current_facts']['row_counts'],
            'report' => $report,
            'backfill' => $backfill,
            'external_order_writes' => 0,
            'external_payment_writes' => 0,
            'scheduler_allowlisted' => false,
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
            $diffs[] = ['code' => 'mig_p4b_subscription_checkpoint_phase_mismatch'];
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
        $facts = $snapshot['current_facts'];
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
        if (!empty($snapshot['plan']['periods']) || !empty($snapshot['plan']['watermarks'])) {
            $diffs[] = ['code' => 'mig_p4b_subscription_backfill_incomplete'];
        }
        if (empty($facts['report']['ok'])) {
            $diffs[] = ['code' => self::ERROR_INTEGRITY];
        }
        if ($snapshot['active_lease_count'] > 0) {
            $diffs[] = ['code' => self::ERROR_ACTIVE_LEASE];
        }

        $applyEvent = $this->lastEventDetail(
            $stored['journal'],
            'p4b_subscription_shadow_applied',
        );
        if ($applyEvent === null) {
            $diffs[] = ['code' => 'mig_p4b_subscription_apply_journal_missing'];
        } elseif (!hash_equals(
            (string) ($applyEvent['report']['report_hash'] ?? ''),
            (string) ($facts['report']['report_hash'] ?? ''),
        )) {
            $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
        }

        $configuration = $this->runtimeRollout()->configuration();
        if (!in_array($configuration['mode'], [
            SubscriptionRolloutGate::MODE_SHADOW,
            SubscriptionRolloutGate::MODE_ALLOWLIST,
            SubscriptionRolloutGate::MODE_OFF,
        ], true)) {
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
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'mode' => $configuration['mode'],
            'allowlist' => $configuration['allowlist_rows'],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'report' => $facts['report'],
            'fresh_journal' => $fresh,
            'external_order_writes' => 0,
            'external_payment_writes' => 0,
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
        if (!in_array((string) $verified['mode'], [
            SubscriptionRolloutGate::MODE_SHADOW,
            SubscriptionRolloutGate::MODE_ALLOWLIST,
        ], true)) {
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
        $subject = SubscriptionRolloutGate::scopeKey($websiteId);
        $before = $rollout->configuration();
        $rollout->setMode(
            self::CAPABILITY,
            SubscriptionRolloutGate::MODE_ALLOWLIST,
            [$subject],
        );
        $readback = $rollout->configuration();
        if ($readback['mode'] !== SubscriptionRolloutGate::MODE_ALLOWLIST
            || array_keys($readback['allowlist']) !== [$subject]
        ) {
            throw new \RuntimeException('mig_p4b_subscription_allowlist_readback_failed');
        }
        $this->checkpoint($target['guard'])->appendJournal(
            $checkpointId,
            'p4b_subscription_allowlist_applied',
            [
                'database' => $target['database'],
                'website_id' => $websiteId,
                'subject' => $subject,
                'verified_manifest_hash' => $verified['manifest_hash'],
                'replayed' => $before['mode'] === SubscriptionRolloutGate::MODE_ALLOWLIST
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
            'scheduler_allowlisted' => true,
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
        // Rollback remains available after legitimate allowlisted billing has
        // advanced facts. It requires a valid checkpoint and current
        // conservation, not equality with the original cutover snapshot.
        $beforeSnapshot = $this->snapshot($websiteId);
        $factsBefore = $beforeSnapshot['current_facts'];
        if (empty($factsBefore['report']['ok'])
            || $beforeSnapshot['active_lease_count'] > 0
        ) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_INTEGRITY,
                'report' => $factsBefore['report'],
            ];
        }

        $before = $this->runtimeRollout()->configuration();
        $checkpoint->rollbackGuard($checkpointId);
        $this->runtimeRollout()->setMode(
            self::CAPABILITY,
            SubscriptionRolloutGate::MODE_OFF,
        );
        $readback = $this->runtimeRollout()->configuration();
        if ($readback['mode'] !== SubscriptionRolloutGate::MODE_OFF
            || $readback['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p4b_subscription_rollback_readback_failed');
        }
        $facts = $this->snapshot($websiteId)['current_facts'];
        if (!$this->sameFacts($factsBefore, $facts)) {
            throw new \RuntimeException('mig_p4b_subscription_rollback_changed_facts');
        }
        $checkpoint->appendJournal($checkpointId, 'p4b_subscription_mode_off', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'periods_retained' => true,
            'orders_retained' => true,
            'attempts_retained' => true,
            'recover_existing_obligations' => true,
            'replayed' => $before['mode'] === SubscriptionRolloutGate::MODE_OFF
                && $before['allowlist'] === [],
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => SubscriptionRolloutGate::MODE_OFF,
            'allowlist' => [],
            'fact_hash' => $facts['row_hashes']['combined'],
            'row_counts' => $facts['row_counts'],
            'periods_retained' => true,
            'billed_orders_retained' => true,
            'attempts_retained' => true,
            'new_scheduler_ticks_blocked' => true,
            'recover_still_allowed' => true,
            'checkpoint_retained' => true,
        ];
    }

    /**
     * @return array{
     *   sample_count:int,
     *   active_lease_count:int,
     *   apply_ready:bool,
     *   diffs:list<array<string,mixed>>,
     *   plan:array{
     *     periods:list<array<string,mixed>>,
     *     watermarks:list<array<string,mixed>>
     *   },
     *   current_facts:array<string,mixed>,
     *   expected_facts:array<string,mixed>
     * }
     */
    private function snapshot(int $websiteId): array
    {
        $rows = $this->scopedRows($websiteId);
        $plan = $this->backfillPlan($rows);
        $expectedRows = $this->applyPlanToRows($rows, $plan);
        $currentFacts = $this->facts($rows);
        $expectedFacts = $this->facts($expectedRows);
        $diffs = [];
        if ($expectedFacts['report']['active_lease_count'] > 0) {
            $diffs[] = ['code' => self::ERROR_ACTIVE_LEASE];
        }
        foreach ($expectedRows['subscriptions'] as $subscription) {
            if ((string) $subscription['environment'] !== SubscriptionState::ENV_SANDBOX) {
                $diffs[] = [
                    'code' => 'mig_p4b_subscription_non_sandbox_scope',
                    'subscription_id' => $subscription['subscription_id'],
                ];
            }
        }
        if (empty($expectedFacts['report']['ok'])) {
            $diffs[] = [
                'code' => self::ERROR_INTEGRITY,
                'report_hash' => $expectedFacts['report']['report_hash'],
            ];
        }

        return [
            'sample_count' => count($expectedRows['subscriptions']),
            'active_lease_count' => (int) $expectedFacts['report']['active_lease_count'],
            'apply_ready' => $expectedRows['subscriptions'] !== [] && $diffs === [],
            'diffs' => $diffs,
            'plan' => $plan,
            'current_facts' => $currentFacts,
            'expected_facts' => $expectedFacts,
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array{
     *   periods:list<array<string,mixed>>,
     *   watermarks:list<array<string,mixed>>
     * }
     */
    private function backfillPlan(array $rows): array
    {
        $periodByIndex = [];
        foreach ($rows['periods'] as $period) {
            $periodByIndex[
                (string) $period['subscription_id'] . '#' . (int) $period['period_index']
            ] = $period;
        }
        $periods = [];
        foreach ($rows['subscriptions'] as $subscription) {
            $subscriptionId = (string) $subscription['subscription_id'];
            $provider = (new SubscriptionProviderRegistry())->get(
                (string) $subscription['provider_code'],
            );
            for (
                $periodIndex = 1;
                $periodIndex < (int) $subscription['current_period_index'];
                $periodIndex++
            ) {
                if (isset($periodByIndex[$subscriptionId . '#' . $periodIndex])) {
                    continue;
                }
                $periods[] = [
                    'subscription_id' => $subscriptionId,
                    'period_index' => $periodIndex,
                    'period_key' => $provider->periodKey($subscriptionId, $periodIndex),
                    'website_id' => (int) $subscription['website_id'],
                    'status' => SubscriptionState::PERIOD_MISSED,
                    'order_ref' => null,
                    'missed_reason' => self::BACKFILL_REASON,
                ];
            }
        }
        usort(
            $periods,
            static fn (array $left, array $right): int
                => [$left['subscription_id'], $left['period_index']]
                    <=> [$right['subscription_id'], $right['period_index']],
        );

        $allPeriods = array_merge($rows['periods'], $periods);
        $currentWatermark = [];
        foreach ($rows['watermarks'] as $watermark) {
            $currentWatermark[(string) $watermark['subscription_id']] = (int) $watermark['period_index'];
        }
        $watermarks = [];
        usort(
            $allPeriods,
            static fn (array $left, array $right): int
                => [$left['subscription_id'], $left['period_index']]
                    <=> [$right['subscription_id'], $right['period_index']],
        );
        foreach ($allPeriods as $period) {
            if ((string) $period['status'] !== SubscriptionState::PERIOD_MISSED) {
                continue;
            }
            $subscriptionId = (string) $period['subscription_id'];
            $periodIndex = (int) $period['period_index'];
            if ($periodIndex <= (int) ($currentWatermark[$subscriptionId] ?? 0)) {
                continue;
            }
            $watermarks[] = [
                'subscription_id' => $subscriptionId,
                'period_index' => $periodIndex,
                'period_key' => (string) $period['period_key'],
                'reason' => (string) ($period['missed_reason'] ?? self::BACKFILL_REASON),
            ];
            $currentWatermark[$subscriptionId] = $periodIndex;
        }

        return ['periods' => $periods, 'watermarks' => $watermarks];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @param array{
     *   periods:list<array<string,mixed>>,
     *   watermarks:list<array<string,mixed>>
     * } $plan
     * @return array<string,list<array<string,mixed>>>
     */
    private function applyPlanToRows(array $rows, array $plan): array
    {
        $expected = $rows;
        $expected['periods'] = array_merge($expected['periods'], $plan['periods']);
        $watermarks = [];
        foreach ($expected['watermarks'] as $row) {
            $watermarks[(string) $row['subscription_id']] = $row;
        }
        foreach ($plan['watermarks'] as $row) {
            $watermarks[(string) $row['subscription_id']] = $row;
        }
        $expected['watermarks'] = array_values($watermarks);

        return $expected;
    }

    /**
     * @param array{
     *   periods:list<array<string,mixed>>,
     *   watermarks:list<array<string,mixed>>
     * } $plan
     * @return array<string,mixed>
     */
    private function executeBackfill(array $plan): array
    {
        $periodStore = $this->memoryTarget
            ? $this->scheduler()->subscriptions()->periods()
            : new SubscriptionPeriodStore();
        $watermarkStore = $this->memoryTarget
            ? $this->scheduler()->missed()
            : new SubscriptionMissedWatermarkStore();

        foreach ($plan['periods'] as $row) {
            $periodStore->openPeriod([
                'subscription_id' => $row['subscription_id'],
                'period_index' => $row['period_index'],
                'period_key' => $row['period_key'],
                'website_id' => $row['website_id'],
            ]);
            $periodStore->markMissed((string) $row['period_key'], self::BACKFILL_REASON);
        }
        foreach ($plan['watermarks'] as $row) {
            $watermarkStore->record(
                (string) $row['subscription_id'],
                (int) $row['period_index'],
                (string) $row['period_key'],
                (string) $row['reason'],
            );
        }

        return [
            'period_rows_written' => count($plan['periods']),
            'watermark_events_written' => count($plan['watermarks']),
            'external_order_writes' => 0,
            'external_payment_writes' => 0,
        ];
    }

    /**
     * @return array{
     *   subscriptions:list<array<string,mixed>>,
     *   periods:list<array<string,mixed>>,
     *   attempts:list<array<string,mixed>>,
     *   watermarks:list<array<string,mixed>>,
     *   leases:list<array<string,mixed>>
     * }
     */
    private function scopedRows(int $websiteId): array
    {
        $rows = $this->memoryTarget ? $this->loadMemoryRows() : $this->loadOrmRows();
        $subscriptions = array_values(array_filter(
            $rows['subscriptions'],
            static fn (array $row): bool => (int) ($row['website_id'] ?? -1) === $websiteId,
        ));
        $subscriptionIds = [];
        foreach ($subscriptions as $row) {
            $subscriptionIds[(string) ($row['subscription_id'] ?? '')] = true;
        }
        $withinSubscription = static fn (array $row): bool
            => isset($subscriptionIds[(string) ($row['subscription_id'] ?? '')]);

        return [
            'subscriptions' => $subscriptions,
            'periods' => array_values(array_filter($rows['periods'], $withinSubscription)),
            'attempts' => array_values(array_filter($rows['attempts'], $withinSubscription)),
            'watermarks' => array_values(array_filter($rows['watermarks'], $withinSubscription)),
            'leases' => array_values(array_filter($rows['leases'], $withinSubscription)),
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function loadOrmRows(): array
    {
        $rows = [];
        foreach (self::FACT_MODELS as $name => $modelClass) {
            $model = ObjectManager::create($modelClass, [], false);
            if (!$model instanceof Model) {
                throw new \LogicException('subscription_migration_model_unavailable:' . $modelClass);
            }
            $rows[$name] = array_values($model->clear()->select()->fetchArray());
        }

        return $rows;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function loadMemoryRows(): array
    {
        $scheduler = $this->scheduler();
        $subscriptions = [];
        $periods = [];
        $attempts = [];
        $watermarks = [];
        $leases = [];
        foreach ($this->memorySubscriptionIds as $subscriptionId) {
            $subscriptions[] = $scheduler->subscriptions()->get($subscriptionId);
            foreach ($scheduler->subscriptions()->periods()->listForSubscription($subscriptionId) as $period) {
                $periods[] = $period;
                $attempt = $scheduler->attempts()->latestForPeriod((string) $period['period_key']);
                if ($attempt !== null) {
                    $attempts[] = $attempt;
                }
            }
            $watermark = $scheduler->missed()->watermark($subscriptionId);
            if ($watermark > 0) {
                $event = null;
                foreach ($scheduler->missed()->events() as $candidate) {
                    if ((string) $candidate['subscription_id'] === $subscriptionId
                        && (int) $candidate['period_index'] === $watermark
                    ) {
                        $event = $candidate;
                    }
                }
                if ($event !== null) {
                    $watermarks[] = $event;
                }
            }
            $holder = $scheduler->leases()->holder($subscriptionId);
            if ($holder !== null) {
                $leases[] = [
                    'subscription_id' => $subscriptionId,
                    'worker_id' => $holder,
                    'expires_at_epoch' => time() + 1,
                ];
            }
        }

        return compact('subscriptions', 'periods', 'attempts', 'watermarks', 'leases');
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array<string,mixed>
     */
    private function facts(array $rows): array
    {
        $projected = [];
        $rowCounts = [];
        $rowHashes = [];
        foreach ($rows as $name => $factRows) {
            $projected[$name] = $this->projectRows(
                $factRows,
                self::FACT_FIELDS[$name],
                self::INT_FIELDS[$name],
            );
            $rowCounts[$name] = count($projected[$name]);
            $rowHashes[$name] = $this->hashPayload($projected[$name]);
        }
        $rowHashes['combined'] = $this->hashPayload($rowHashes);
        $report = (new SubscriptionShadowComparator())->compare($projected);

        return [
            'row_counts' => $rowCounts,
            'row_hashes' => $rowHashes,
            'schema_fingerprints' => $this->schemaFingerprints(),
            'report' => $report,
            '_rows' => $projected,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @param list<string> $intFields
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $rows, array $fields, array $intFields): array
    {
        $projected = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;
                $item[$field] = in_array($field, $intFields, true) ? (int) $value : $value;
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

    private function runtimeRollout(): SubscriptionRolloutGate
    {
        return $this->rolloutGate ??= $this->memoryTarget
            ? SubscriptionRolloutGate::forTestingConfiguration()
            : SubscriptionRolloutGate::forConnection(ConnectionFactory::getInstance());
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
     * @param array<string,mixed> $snapshot
     */
    private function manifest(
        string $checkpointId,
        array $target,
        array $facts,
        array $snapshot,
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
            'watermarks' => [
                'website_id' => (int) (
                    $facts['_rows']['subscriptions'][0]['website_id'] ?? -1
                ),
                'subscription_count' => (int) $snapshot['sample_count'],
                'gap_period_count' => count($snapshot['plan']['periods']),
                'watermark_event_count' => count($snapshot['plan']['watermarks']),
                'max_period_index' => $this->maxInt(
                    $facts['_rows']['periods'],
                    'period_index',
                ),
                'max_missed_watermark' => $this->maxInt(
                    $facts['_rows']['watermarks'],
                    'period_index',
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
            'ok' => $snapshot['diffs'] === [],
            'sample_count' => $snapshot['sample_count'],
            'active_lease_count' => $snapshot['active_lease_count'],
            'diff_count' => count($snapshot['diffs']),
            'diffs' => $snapshot['diffs'],
            'gap_period_count' => count($snapshot['plan']['periods']),
            'watermark_event_count' => count($snapshot['plan']['watermarks']),
            'current_row_counts' => $snapshot['current_facts']['row_counts'],
            'current_fact_hash' => $snapshot['current_facts']['row_hashes']['combined'],
            'expected_row_counts' => $snapshot['expected_facts']['row_counts'],
            'expected_fact_hash' => $snapshot['expected_facts']['row_hashes']['combined'],
            'expected_report' => $snapshot['expected_facts']['report'],
            'external_order_writes' => 0,
            'external_payment_writes' => 0,
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

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function forcedMismatch(array $report): array
    {
        $report['ok'] = false;
        $report['conserved'] = false;
        $report['diffs'][] = ['code' => 'forced_shadow_mismatch'];
        $report['unclassified_diff_count'] = count($report['diffs']);
        unset($report['report_hash']);
        $report['report_hash'] = hash(
            'sha256',
            json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $report;
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
            throw new \LogicException('subscription_migration_fixture_is_test_only');
        }
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
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
