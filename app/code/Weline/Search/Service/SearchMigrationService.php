<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Api\ProductDirectCatalogReaderInterface;
use Weline\Search\Api\SearchDegradeMarkerStoreInterface;
use Weline\Search\Model\SearchServingAlias;
use Weline\Search\Model\Shard\SearchWatermark;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Durable Search full build -> shadow -> fresh verify -> alias CAS migration.
 *
 * Production actions bind a registry-approved full clone before resolving any
 * Product/Search dependency. Process-local data exists only in forTesting().
 */
final class SearchMigrationService
{
    public const PHASE = 'p3c-search';
    public const CAPABILITY = SearchRolloutGate::CAPABILITY;

    public const ERROR_SHARED_DB = 'mig_p3c_search_requires_isolated_database';
    public const ERROR_UNREGISTERED_CLONE = 'mig_p3c_search_registered_clone_required';
    public const ERROR_FULL_CLONE = 'mig_p3c_search_full_clone_required';
    public const ERROR_FINGERPRINT = 'mig_p3c_search_checkpoint_fingerprint_mismatch';
    public const ERROR_SCOPE = 'mig_p3c_search_scope_required';
    public const ERROR_NO_SAMPLE = 'mig_p3c_search_sample_required';
    public const ERROR_SHADOW_DIFF = 'mig_p3c_search_shadow_diff';
    public const ERROR_WATERMARK = 'mig_p3c_search_watermark_not_caught_up';
    public const ERROR_DOCUMENTS = 'mig_p3c_search_documents_not_equal';
    public const ERROR_ALIAS_CAS = 'mig_p3c_search_alias_cas_conflict';
    public const ERROR_ALIAS_NOT_DIRECT = 'mig_p3c_search_direct_alias_required';
    public const ERROR_CHECKPOINT = 'mig_p3c_search_checkpoint_required';
    public const ERROR_VERIFY = 'mig_p3c_search_fresh_verify_required';
    public const ERROR_SCOPE_MISMATCH = 'mig_p3c_search_allowlist_scope_mismatch';
    public const ERROR_MODE = 'mig_p3c_search_mode_invalid';

    private const MAX_STABLE_READ_ATTEMPTS = 6;
    private const MAX_CATCH_UP_BUILDS = 3;
    private const MAX_ROLLBACK_CAS_ATTEMPTS = 8;

    /** @var list<array<string,mixed>> */
    private array $memoryPublished = [];

    /** @var list<array<string,mixed>> */
    private array $memoryIncrementalEvents = [];

    /** @var list<array<string,mixed>> */
    private array $memoryShadowQueries = [];

    /** @var list<array<string,mixed>> */
    private array $memoryAudit = [];

    private bool $memoryTarget = false;
    private ?SearchIndexBuilder $builder = null;
    private ?ProductDirectCatalogReaderInterface $direct = null;
    private ?SearchRolloutGate $rollout = null;
    private ?SearchAliasStore $alias = null;
    private ?SearchDegradeMarker $degrade = null;
    private ?SearchQueryService $query = null;
    private ?SearchShadowComparator $comparator = null;
    private ?SearchIndexIncrementalApplier $applier = null;
    private ?DatabaseFingerprintGuard $fingerprintGuard = null;
    private ?MigrationCheckpointService $checkpointService = null;

    public function __construct()
    {
    }

    public static function forTesting(
        ?string $journalDir = null,
        ?SearchIndexBuilder $builder = null,
        ?ProductDirectCatalogReaderInterface $direct = null,
        ?SearchRolloutGate $rollout = null,
        ?SearchAliasStore $alias = null,
        ?SearchDegradeMarker $degrade = null,
    ): self {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? \sys_get_temp_dir() . '/mig_p3csearch_' . \uniqid('', true),
        );
        $service = new self();
        $service->memoryTarget = true;
        $service->fingerprintGuard = $guard;
        $service->checkpointService = new MigrationCheckpointService($guard, $store);
        $service->builder = $builder ?? SearchIndexBuilder::forTesting();
        $service->direct = $direct ?? ArrayProductDirectCatalogReader::forTesting();
        $service->rollout = $rollout ?? SearchRolloutGate::forTestingConfiguration();
        $service->alias = $alias ?? SearchAliasStore::forTesting();
        $service->degrade = $degrade ?? SearchDegradeMarker::forTesting();

        return $service;
    }

    public function builder(): SearchIndexBuilder
    {
        return $this->runtimeBuilder();
    }

    public function alias(): SearchAliasStore
    {
        return $this->runtimeAlias();
    }

    public function rollout(): SearchRolloutGate
    {
        return $this->runtimeRollout();
    }

    public function query(): SearchQueryService
    {
        return $this->runtimeQuery();
    }

    public function direct(): ProductDirectCatalogReaderInterface
    {
        return $this->runtimeDirect();
    }

    /** @param array<string,mixed> $document */
    public function seedPublished(array $document): void
    {
        $this->assertTesting();
        $this->memoryPublished[] = $document;
        $this->refreshTestingSnapshot();
    }

    /** @param array<string,mixed> $document */
    public function seedDirect(array $document): void
    {
        $this->assertTesting();
        if (!$this->runtimeDirect() instanceof ArrayProductDirectCatalogReader) {
            throw new \LogicException('search_migration_direct_seed_requires_array_reader');
        }
        $this->runtimeDirect()->seed($document);
    }

    /** @param array<string,mixed> $event */
    public function seedIncremental(array $event): void
    {
        $this->assertTesting();
        $this->memoryIncrementalEvents[] = $event;
    }

    /** @param array<string,mixed> $query */
    public function seedShadowQuery(array $query): void
    {
        $this->assertTesting();
        $this->memoryShadowQueries[] = $query;
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
        string $locale,
        string $currency,
    ): array {
        $scope = $this->scope(
            $websiteId,
            $storeId,
            $channelId,
            $locale,
            $currency,
        );
        $target = $this->prepareTarget($targetDb);

        return $this->publicPreflight($this->inspect($target, $scope));
    }

    /**
     * Build a stable full generation and persist shadow evidence. No serving
     * alias or allowlist is changed by this action.
     *
     * @param array<string,mixed>|null $targetDb
     * @return array<string,mixed>
     */
    public function apply(
        ?array $targetDb,
        int $websiteId,
        int $storeId,
        int $channelId,
        string $locale,
        string $currency,
    ): array {
        $scope = $this->scope(
            $websiteId,
            $storeId,
            $channelId,
            $locale,
            $currency,
        );
        $target = $this->prepareTarget($targetDb);
        $preflight = $this->inspect($target, $scope);
        if (empty($preflight['ok'])) {
            return $this->publicPreflight($preflight);
        }
        if (empty($preflight['apply_ready'])) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $preflight['alias_state']['alias'] !== SearchAliasStore::ALIAS_DIRECT
                    ? self::ERROR_ALIAS_NOT_DIRECT
                    : self::ERROR_MODE,
                'preflight' => $this->publicPreflight($preflight),
            ];
        }

        $rollout = $this->runtimeRollout();
        $configuration = $rollout->configuration();
        if (!empty($configuration['env_locked'])) {
            if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW) {
                throw new \RuntimeException('search_rollout_env_lock_must_be_shadow_for_apply');
            }
        } else {
            $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        }
        $readback = $rollout->configuration();
        if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW
            || $readback['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p3c_search_shadow_readback_failed');
        }

        $checkpointId = $this->newCheckpointId();
        $manifest = $this->manifest($checkpointId, $target, $preflight);
        $checkpoint = $this->checkpoint($target['guard']);
        $checkpoint->checkpoint($manifest);
        $checkpoint->appendJournal($checkpointId, 'p3c_search_preflight_snapshot', [
            'database' => $target['database'],
            'scope' => $scope,
            'source_watermark' => $preflight['source_watermark'],
            'source_document_count' => $preflight['source_document_count'],
            'source_document_hash' => $preflight['source_document_hash'],
            'product_snapshot_hash' => $preflight['product_snapshot_hash'],
            'shadow_queries' => $preflight['shadow_queries'],
            'shadow_query_hash' => $this->hash($preflight['shadow_queries']),
            'alias_before' => $preflight['alias_state'],
            'rollout_before' => $preflight['rollout'],
        ]);
        $checkpoint->applyGuard($target['db'], $checkpointId, $manifest);

        $builder = $this->runtimeBuilder();
        $rebuilds = [];
        $rebuilds[] = $builder->rebuildWebsite(
            $websiteId,
            $this->memoryTarget ? $this->memoryPublished : null,
        );

        if ($this->memoryTarget) {
            foreach ($this->memoryIncrementalEvents as $event) {
                $this->runtimeApplier()->apply($event);
                $this->applyTestingEventToPublished($event);
            }
            if ($this->memoryIncrementalEvents !== []) {
                $this->refreshTestingSnapshot();
                $rebuilds[] = $builder->rebuildWebsite(
                    $websiteId,
                    $this->memoryPublished,
                );
            }
        }

        $evidence = null;
        for ($attempt = 0; $attempt < self::MAX_CATCH_UP_BUILDS; $attempt++) {
            $evidence = $this->evidence($websiteId);
            if (!empty($evidence['caught_up']) && !empty($evidence['documents_equal'])) {
                break;
            }
            if ($attempt + 1 < self::MAX_CATCH_UP_BUILDS) {
                $rebuilds[] = $builder->rebuildWebsite(
                    $websiteId,
                    $this->memoryTarget ? $this->memoryPublished : null,
                );
            }
        }
        if (!\is_array($evidence)
            || empty($evidence['caught_up'])
            || empty($evidence['documents_equal'])
        ) {
            $error = empty($evidence['caught_up'])
                ? self::ERROR_WATERMARK
                : self::ERROR_DOCUMENTS;
            $checkpoint->appendJournal($checkpointId, 'p3c_search_catch_up_rejected', [
                'error' => $error,
                'evidence' => $evidence,
                'rebuilds' => $rebuilds,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $error,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => $target['database'],
                'mode' => $readback['mode'],
                'alias_state' => $this->runtimeAlias()->state($websiteId),
                'fallback' => 'product_direct',
                'evidence' => $evidence,
            ];
        }

        $report = $this->runtimeComparator()->observe($preflight['shadow_queries']);
        if (empty($report['ok'])
            || (int)($report['unclassified_diff_count'] ?? -1) !== 0
            || empty($report['conserved'])
        ) {
            $checkpoint->appendJournal($checkpointId, 'p3c_search_shadow_rejected', [
                'report' => $report,
                'evidence' => $evidence,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => self::ERROR_SHADOW_DIFF,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => $target['database'],
                'mode' => $readback['mode'],
                'alias_state' => $this->runtimeAlias()->state($websiteId),
                'fallback' => 'product_direct',
                'report' => $report,
                'evidence' => $evidence,
            ];
        }

        $checkpoint->appendJournal($checkpointId, 'p3c_search_shadow_applied', [
            'database' => $target['database'],
            'scope' => $scope,
            'evidence' => $evidence,
            'evidence_hash' => $this->hash($evidence),
            'report' => $report,
            'report_hash' => (string)$report['report_hash'],
            'rebuilds' => $rebuilds,
            'alias_before' => $preflight['alias_state'],
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist_ready' => false,
        ]);
        $this->memoryAudit[] = [
            'type' => 'shadow_applied',
            'checkpoint_id' => $checkpointId,
            'evidence_hash' => $this->hash($evidence),
        ];

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist_ready' => false,
            'alias_state' => $this->runtimeAlias()->state($websiteId),
            'fallback' => 'product_direct',
            'evidence' => $evidence,
            'report' => $report,
            'rebuilds' => $rebuilds,
        ];
    }

    /**
     * Fresh checkpoint reload + Product/Search evidence recomputation.
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
        $journal = $stored['journal'];
        $diffs = [];
        if ($manifest->phase !== self::PHASE . '-shadow') {
            $diffs[] = ['code' => 'mig_p3c_search_checkpoint_phase_mismatch'];
        }
        if (!\hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            $diffs[] = ['code' => self::ERROR_FINGERPRINT];
        }

        $preflightEvent = $this->lastEventDetail($journal, 'p3c_search_preflight_snapshot');
        $applyEvent = $this->lastEventDetail($journal, 'p3c_search_shadow_applied');
        if ($preflightEvent === null) {
            $diffs[] = ['code' => 'mig_p3c_search_preflight_journal_missing'];
        }
        if ($applyEvent === null) {
            $diffs[] = ['code' => 'mig_p3c_search_apply_journal_missing'];
        }

        $websiteId = (int)($manifest->watermarks['website_id'] ?? -1);
        $scope = \is_array($applyEvent['scope'] ?? null)
            ? $applyEvent['scope']
            : (\is_array($preflightEvent['scope'] ?? null) ? $preflightEvent['scope'] : []);
        try {
            $scope = $this->scope(
                $websiteId,
                (int)($scope['store_id'] ?? 0),
                (int)($scope['channel_id'] ?? 0),
                (string)($scope['locale'] ?? ''),
                (string)($scope['currency'] ?? ''),
            );
        } catch (\Throwable $exception) {
            $diffs[] = ['code' => self::ERROR_SCOPE, 'detail' => $exception->getMessage()];
        }

        $evidence = null;
        $report = null;
        if ($websiteId >= 0 && $applyEvent !== null) {
            try {
                $evidence = $this->evidence($websiteId);
                $expectedEvidence = \is_array($applyEvent['evidence'] ?? null)
                    ? $applyEvent['evidence']
                    : [];
                if (empty($evidence['caught_up'])) {
                    $diffs[] = ['code' => self::ERROR_WATERMARK, 'evidence' => $evidence];
                }
                if (empty($evidence['documents_equal'])) {
                    $diffs[] = ['code' => self::ERROR_DOCUMENTS, 'evidence' => $evidence];
                }
                if (!\hash_equals(
                    (string)($applyEvent['evidence_hash'] ?? ''),
                    $this->hash($evidence),
                ) || !$this->sameEvidence($expectedEvidence, $evidence)) {
                    $diffs[] = ['code' => 'mig_p3c_search_evidence_changed'];
                }
            } catch (\Throwable $exception) {
                $diffs[] = [
                    'code' => 'mig_p3c_search_evidence_unavailable',
                    'detail' => $exception->getMessage(),
                ];
            }

            $queries = \is_array($preflightEvent['shadow_queries'] ?? null)
                ? $preflightEvent['shadow_queries']
                : [];
            if ($queries === []
                || !\hash_equals(
                    (string)($preflightEvent['shadow_query_hash'] ?? ''),
                    $this->hash($queries),
                )
            ) {
                $diffs[] = ['code' => 'mig_p3c_search_shadow_query_window_changed'];
            } else {
                try {
                    $report = $this->runtimeComparator()->observe($queries);
                    if (empty($report['ok'])
                        || !\hash_equals(
                            (string)($applyEvent['report_hash'] ?? ''),
                            (string)($report['report_hash'] ?? ''),
                        )
                    ) {
                        $diffs[] = ['code' => self::ERROR_SHADOW_DIFF];
                    }
                } catch (\Throwable $exception) {
                    $diffs[] = [
                        'code' => self::ERROR_SHADOW_DIFF,
                        'detail' => $exception->getMessage(),
                    ];
                }
            }
        }

        $aliasState = $websiteId >= 0
            ? $this->runtimeAlias()->state($websiteId)
            : ['website_id' => $websiteId, 'alias' => '', 'generation' => 0, 'version' => -1];
        $rollout = $this->runtimeRollout()->configuration();
        $servingState = 'shadow';
        $verifiedForAllowlist = false;
        $rollbackEvent = $this->lastEventDetail($journal, 'p3c_search_mode_off');
        $allowlistEvent = $this->lastEventDetail($journal, 'p3c_search_allowlist_applied');
        $expectedGeneration = (int)($applyEvent['evidence']['active_generation'] ?? 0);
        $aliasBefore = \is_array($applyEvent['alias_before'] ?? null)
            ? $applyEvent['alias_before']
            : [];
        if ($rollbackEvent !== null
            && $this->eventIndex($journal, 'p3c_search_mode_off')
                > $this->eventIndex($journal, 'p3c_search_allowlist_applied')
        ) {
            $servingState = 'rolled_back';
            if ($rollout['mode'] !== CommerceRolloutGateInterface::MODE_OFF
                || $rollout['allowlist'] !== []
                || $aliasState['alias'] !== SearchAliasStore::ALIAS_DIRECT
            ) {
                $diffs[] = ['code' => 'mig_p3c_search_rollback_state_mismatch'];
            }
        } elseif ($allowlistEvent !== null) {
            $servingState = 'allowlist';
            $subject = SearchRolloutGate::tupleKey(
                (int)($scope['website_id'] ?? -1),
                (int)($scope['store_id'] ?? 0),
                (int)($scope['channel_id'] ?? 0),
            );
            if ($rollout['mode'] !== CommerceRolloutGateInterface::MODE_ALLOWLIST
                || !isset($rollout['allowlist'][$subject])
                || \count($rollout['allowlist']) !== 1
                || $aliasState['alias'] !== SearchAliasStore::ALIAS_INDEX
                || $aliasState['generation'] !== $expectedGeneration
            ) {
                $diffs[] = ['code' => 'mig_p3c_search_allowlist_state_mismatch'];
            }
        } else {
            if ($rollout['mode'] !== CommerceRolloutGateInterface::MODE_SHADOW
                || $rollout['allowlist'] !== []
                || $aliasState['alias'] !== SearchAliasStore::ALIAS_DIRECT
                || $aliasState['generation'] !== (int)($aliasBefore['generation'] ?? -1)
                || $aliasState['version'] !== (int)($aliasBefore['version'] ?? -1)
            ) {
                $diffs[] = ['code' => 'mig_p3c_search_shadow_state_mismatch'];
            } else {
                $verifiedForAllowlist = true;
            }
        }

        return [
            'ok' => $diffs === [],
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'diff_count' => \count($diffs),
            'diffs' => $diffs,
            'fresh_journal' => $fresh,
            'scope' => $scope,
            'evidence' => $evidence,
            'report' => $report,
            'mode' => $rollout['mode'],
            'allowlist' => \array_values($rollout['allowlist_rows']),
            'alias_state' => $aliasState,
            'expected_generation' => $expectedGeneration,
            'serving_state' => $servingState,
            'verified_for_allowlist' => $diffs === [] && $verifiedForAllowlist,
            'direct_read_available' => true,
            'source_of_truth' => 'product_current_projection',
        ];
    }

    /**
     * Fresh verify then per-Website alias CAS and exact tuple allowlist.
     *
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
        $expectedScope = [
            (int)($verified['scope']['website_id'] ?? -1),
            (int)($verified['scope']['store_id'] ?? 0),
            (int)($verified['scope']['channel_id'] ?? 0),
        ];
        if ($expectedScope !== [$websiteId, $storeId, $channelId]) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_SCOPE_MISMATCH,
                'expected_scope' => $expectedScope,
                'requested_scope' => [$websiteId, $storeId, $channelId],
            ];
        }
        if (($verified['serving_state'] ?? '') === 'allowlist') {
            return [
                'ok' => true,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'database' => $verified['database'],
                'mode' => $verified['mode'],
                'allowlist' => $verified['allowlist'],
                'alias_state' => $verified['alias_state'],
                'fresh_verify' => true,
                'idempotent' => true,
                'production_on' => false,
            ];
        }
        if (empty($verified['verified_for_allowlist'])) {
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
        $applyEvent = $this->lastEventDetail(
            $stored['journal'],
            'p3c_search_shadow_applied',
        ) ?? throw new \RuntimeException('mig_p3c_search_apply_journal_missing');
        $before = \is_array($applyEvent['alias_before'] ?? null)
            ? $applyEvent['alias_before']
            : [];
        $generation = (int)($applyEvent['evidence']['active_generation'] ?? 0);
        $cas = $this->runtimeAlias()->compareAndSwap(
            $websiteId,
            (string)($before['alias'] ?? ''),
            (int)($before['generation'] ?? -1),
            (int)($before['version'] ?? -1),
            SearchAliasStore::ALIAS_INDEX,
            $generation,
        );
        if (empty($cas['ok'])) {
            $checkpoint->appendJournal($checkpointId, 'p3c_search_alias_cas_rejected', [
                'expected' => $before,
                'actual' => $cas,
                'target_generation' => $generation,
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => self::ERROR_ALIAS_CAS,
                'alias_state' => $cas,
                'previous_alias_retained' => true,
                'fallback' => 'product_direct',
            ];
        }

        $subject = SearchRolloutGate::tupleKey($websiteId, $storeId, $channelId);
        try {
            $marker = $this->runtimeDegrade()->get($websiteId);
            if (($marker['active'] ?? false) === true) {
                $this->runtimeDegrade()->clearIfRecovered(
                    $websiteId,
                    (int)$verified['evidence']['incremental_watermark'],
                    (int)$verified['evidence']['source_watermark'],
                );
            }
            $this->runtimeRollout()->setMode(
                self::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                [$subject],
            );
            $readback = $this->runtimeRollout()->configuration();
            if ($readback['mode'] !== CommerceRolloutGateInterface::MODE_ALLOWLIST
                || !isset($readback['allowlist'][$subject])
                || \count($readback['allowlist']) !== 1
            ) {
                throw new \RuntimeException('mig_p3c_search_allowlist_readback_failed');
            }
        } catch (\Throwable $exception) {
            try {
                $this->runtimeRollout()->setMode(
                    self::CAPABILITY,
                    CommerceRolloutGateInterface::MODE_OFF,
                );
            } catch (\Throwable) {
            }
            $this->runtimeAlias()->compareAndSwap(
                $websiteId,
                SearchAliasStore::ALIAS_INDEX,
                $generation,
                (int)$cas['version'],
                SearchAliasStore::ALIAS_DIRECT,
                $generation,
            );
            $checkpoint->appendJournal($checkpointId, 'p3c_search_allowlist_rejected', [
                'error' => $exception->getMessage(),
                'alias_compensation' => $this->runtimeAlias()->state($websiteId),
                'mode' => $this->runtimeRollout()->mode(self::CAPABILITY),
            ]);

            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => 'mig_p3c_search_allowlist_failed:' . $exception->getMessage(),
                'alias_state' => $this->runtimeAlias()->state($websiteId),
                'mode' => $this->runtimeRollout()->mode(self::CAPABILITY),
                'fallback' => 'product_direct',
            ];
        }

        $checkpoint->appendJournal($checkpointId, 'p3c_search_allowlist_applied', [
            'database' => $target['database'],
            'scope' => [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ],
            'subject' => $subject,
            'generation' => $generation,
            'alias_state' => $cas,
            'verified_manifest_hash' => (string)$verified['manifest_hash'],
            'verified_evidence_hash' => $this->hash($verified['evidence']),
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => CommerceRolloutGateInterface::MODE_ALLOWLIST,
            'allowlist' => [[
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ]],
            'alias_state' => $cas,
            'fresh_verify' => true,
            'idempotent' => false,
            'production_on' => false,
        ];
    }

    /**
     * Persist mode off and direct alias while retaining checkpoint/index facts.
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
        if (!\hash_equals($manifest->connectorFingerprint, $target['fingerprint'])) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }
        $websiteId = (int)($manifest->watermarks['website_id'] ?? -1);
        if ($websiteId < 0) {
            throw new \RuntimeException(self::ERROR_SCOPE);
        }

        $checkpoint->rollbackGuard($checkpointId);
        $this->runtimeRollout()->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );
        $alias = $this->runtimeAlias();
        $state = $alias->state($websiteId);
        for ($attempt = 0;
            $state['alias'] !== SearchAliasStore::ALIAS_DIRECT
                && $attempt < self::MAX_ROLLBACK_CAS_ATTEMPTS;
            $attempt++
        ) {
            $cas = $alias->compareAndSwap(
                $websiteId,
                $state['alias'],
                $state['generation'],
                $state['version'],
                SearchAliasStore::ALIAS_DIRECT,
                $state['generation'],
            );
            if (!empty($cas['ok'])) {
                $state = $cas;
                break;
            }
            $state = $alias->state($websiteId);
        }
        $rollout = $this->runtimeRollout()->configuration();
        if ($state['alias'] !== SearchAliasStore::ALIAS_DIRECT
            || $rollout['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $rollout['allowlist'] !== []
        ) {
            throw new \RuntimeException('mig_p3c_search_rollback_readback_failed');
        }
        $checkpoint->appendJournal($checkpointId, 'p3c_search_mode_off', [
            'database' => $target['database'],
            'website_id' => $websiteId,
            'alias_state' => $state,
            'index_facts_retained' => true,
            'checkpoint_retained' => true,
        ]);

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'checkpoint_id' => $checkpointId,
            'database' => $target['database'],
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
            'alias_state' => $state,
            'index_facts_retained' => true,
            'checkpoint_retained' => true,
            'fallback' => 'product_direct',
            'continue_forward' => true,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function audit(): array
    {
        return $this->memoryAudit;
    }

    /**
     * @param array{
     *   db:array<string,mixed>,
     *   guard:DatabaseFingerprintGuard,
     *   fingerprint:string,
     *   database:string
     * } $target
     * @param array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string} $scope
     * @return array<string,mixed>
     */
    private function inspect(array $target, array $scope): array
    {
        $snapshot = $this->stableSnapshot($scope['website_id']);
        $queries = $this->shadowQueries($scope);
        $scopeDocuments = $this->scopeDocuments($snapshot['documents'], $scope);
        $aliasState = $this->runtimeAlias()->state($scope['website_id']);
        $rollout = $this->runtimeRollout()->configuration();
        $sourceHash = $this->documentHash($snapshot['documents']);
        $searchWatermark = $this->runtimeBuilder()->store()->watermark($scope['website_id']);
        $ok = $scopeDocuments !== [] && $queries !== [];
        $modeReady = \in_array(
            $rollout['mode'],
            [
                CommerceRolloutGateInterface::MODE_OFF,
                CommerceRolloutGateInterface::MODE_SHADOW,
            ],
            true,
        );

        return [
            'ok' => $ok,
            'phase' => self::PHASE,
            'error' => $ok ? null : self::ERROR_NO_SAMPLE,
            'database' => $target['database'],
            'fingerprint' => $target['fingerprint'],
            'scope' => $scope,
            'source_watermark' => (int)$snapshot['source_watermark'],
            'source_document_count' => (int)$snapshot['document_count'],
            'source_document_hash' => $sourceHash,
            'product_snapshot_hash' => (string)$snapshot['snapshot_hash'],
            'scope_document_count' => \count($scopeDocuments),
            'shadow_query_count' => \count($queries),
            'shadow_queries' => $queries,
            'search_watermark' => $this->watermark($searchWatermark),
            'alias_state' => $aliasState,
            'rollout' => [
                'mode' => $rollout['mode'],
                'allowlist' => \array_values($rollout['allowlist_rows']),
                'env_locked' => $rollout['env_locked'],
            ],
            'apply_ready' => $ok
                && $modeReady
                && $aliasState['alias'] === SearchAliasStore::ALIAS_DIRECT,
            'shared_db_apply_forbidden' => true,
            'full_clone_required' => !$this->memoryTarget,
            'fallback' => 'product_direct',
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    private function evidence(int $websiteId): array
    {
        $snapshot = $this->stableSnapshot($websiteId);
        $store = $this->runtimeBuilder()->store();
        $watermark = $this->watermark($store->watermark($websiteId));
        $indexDocuments = $store->documentsForWebsite($websiteId);
        $sourceHash = $this->documentHash($snapshot['documents']);
        $indexHash = $this->documentHash($indexDocuments);
        $sourceWatermark = (int)$snapshot['source_watermark'];
        $fullWatermark = (int)$watermark['full_watermark'];
        $incrementalWatermark = (int)$watermark['incremental_watermark'];
        $registry = $this->runtimeBuilder()->registry();

        return [
            'website_id' => $websiteId,
            'source_watermark' => $sourceWatermark,
            'full_watermark' => $fullWatermark,
            'incremental_watermark' => $incrementalWatermark,
            'active_generation' => (int)$watermark['active_generation'],
            'build_generation' => (int)$watermark['build_generation'],
            'build_status' => (string)$watermark['build_status'],
            'row_version' => (int)$watermark['row_version'],
            'source_document_count' => (int)$snapshot['document_count'],
            'index_document_count' => \count($indexDocuments),
            'source_document_hash' => $sourceHash,
            'index_document_hash' => $indexHash,
            'product_snapshot_hash' => (string)$snapshot['snapshot_hash'],
            'shard_fingerprint' => $registry->getFingerprint($websiteId),
            'shard_schema_version' => $registry->getSchemaVersion($websiteId),
            'caught_up' => $sourceWatermark === $fullWatermark
                && $fullWatermark === $incrementalWatermark
                && (int)$watermark['active_generation'] >= 1
                && (int)$watermark['build_generation'] === 0
                && (string)$watermark['build_status'] === SearchWatermark::BUILD_IDLE,
            'documents_equal' => (int)$snapshot['document_count'] === \count($indexDocuments)
                && \hash_equals($sourceHash, $indexHash),
        ];
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
        $db = $this->requireIsolatedTarget($targetDb);
        if ($this->memoryTarget) {
            $guard = $this->fingerprintGuard ?? new DatabaseFingerprintGuard();

            return [
                'db' => $db,
                'guard' => $guard,
                'fingerprint' => $guard->assertIsolatedDatabase($db),
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
        if (!\hash_equals($handle->fingerprint, $fingerprint)) {
            throw new \RuntimeException(self::ERROR_FINGERPRINT);
        }
        (new MigrationTargetBinder())->bindIsolated($db);

        return [
            'db' => $db,
            'guard' => $guard,
            'fingerprint' => $fingerprint,
            'database' => (string)$db['database'],
        ];
    }

    /** @param array<string,mixed>|null $targetDb @return array<string,mixed> */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        $database = \strtolower(\trim((string)($targetDb['database'] ?? '')));
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
        ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())
            ->assertIsolatedDatabase($db);

        return $db;
    }

    /**
     * @return array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string}
     */
    private function scope(
        int $websiteId,
        int $storeId,
        int $channelId,
        string $locale,
        string $currency,
    ): array {
        $locale = \trim($locale);
        $currency = \strtoupper(\trim($currency));
        if ($websiteId < 0 || $storeId < 1 || $channelId < 1
            || $locale === '' || $currency === ''
        ) {
            throw new \InvalidArgumentException(
                self::ERROR_SCOPE
                . ': require website>=0, store>=1, channel>=1, locale and currency',
            );
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'locale' => $locale,
            'currency' => $currency,
        ];
    }

    /** @return array<string,mixed> */
    private function stableSnapshot(int $websiteId): array
    {
        $source = $this->runtimeBuilder()->source();
        for ($attempt = 0; $attempt < self::MAX_STABLE_READ_ATTEMPTS; $attempt++) {
            $before = $source->currentWatermark($websiteId);
            $snapshot = $source->snapshotWebsite($websiteId);
            $after = $source->currentWatermark($websiteId);
            if (($snapshot['contract'] ?? null) !== 'product.search_projection_snapshot.v1'
                || (int)($snapshot['website_id'] ?? -1) !== $websiteId
                || !\is_array($snapshot['documents'] ?? null)
                || (int)($snapshot['document_count'] ?? -1) !== \count($snapshot['documents'])
                || \preg_match(
                    '/^[a-f0-9]{64}$/D',
                    (string)($snapshot['snapshot_hash'] ?? ''),
                ) !== 1
            ) {
                throw new \UnexpectedValueException('product_search_snapshot_invalid');
            }
            if ($before === $after && $after === (int)$snapshot['source_watermark']) {
                return $snapshot;
            }
        }

        throw new \RuntimeException('mig_p3c_search_source_snapshot_not_stable');
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @param array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string} $scope
     * @return list<array<string,mixed>>
     */
    private function scopeDocuments(array $documents, array $scope): array
    {
        $matched = [];
        foreach ($documents as $document) {
            if (!\is_array($document)
                || (int)($document['website_id'] ?? -1) !== $scope['website_id']
                || (int)($document['store_id'] ?? 0) !== $scope['store_id']
                || (int)($document['channel_id'] ?? 0) !== $scope['channel_id']
            ) {
                continue;
            }
            $locale = \trim((string)($document['locale'] ?? ''));
            $currency = \strtoupper(\trim((string)($document['currency'] ?? '')));
            if (($locale === '' && $currency === '')
                || ($locale === $scope['locale'] && $currency === $scope['currency'])
            ) {
                $matched[] = $document;
            }
        }

        return $matched;
    }

    /**
     * @param array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string} $scope
     * @return list<array<string,mixed>>
     */
    private function shadowQueries(array $scope): array
    {
        if ($this->memoryTarget && $this->memoryShadowQueries !== []) {
            return \array_values($this->memoryShadowQueries);
        }

        return [$scope + ['q' => '']];
    }

    /** @param list<array<string,mixed>> $documents */
    private function documentHash(array $documents): string
    {
        $canonical = [];
        foreach ($documents as $document) {
            if (!\is_array($document)) {
                throw new \UnexpectedValueException('search_migration_document_invalid');
            }
            $row = \array_intersect_key($document, \array_flip([
                'entity_type',
                'entity_id',
                'website_id',
                'website_code',
                'store_id',
                'store_code',
                'channel_id',
                'channel_code',
                'locale',
                'currency',
                'document_version',
                'title',
                'sku',
                'status',
            ]));
            \ksort($row);
            $canonical[] = $row;
        }
        \usort(
            $canonical,
            static fn(array $left, array $right): int => [
                (int)($left['website_id'] ?? -1),
                (int)($left['store_id'] ?? 0),
                (int)($left['channel_id'] ?? 0),
                (string)($left['locale'] ?? ''),
                (string)($left['currency'] ?? ''),
                (string)($left['entity_type'] ?? ''),
                (string)($left['entity_id'] ?? ''),
            ] <=> [
                (int)($right['website_id'] ?? -1),
                (int)($right['store_id'] ?? 0),
                (int)($right['channel_id'] ?? 0),
                (string)($right['locale'] ?? ''),
                (string)($right['currency'] ?? ''),
                (string)($right['entity_type'] ?? ''),
                (string)($right['entity_id'] ?? ''),
            ],
        );

        return $this->hash($canonical);
    }

    /** @param array<string,mixed> $watermark @return array<string,mixed> */
    private function watermark(array $watermark): array
    {
        return [
            'website_id' => (int)($watermark['website_id'] ?? -1),
            'active_generation' => (int)($watermark['active_generation'] ?? 0),
            'build_generation' => (int)($watermark['build_generation'] ?? 0),
            'build_source_watermark' => (int)($watermark['build_source_watermark'] ?? 0),
            'full_watermark' => (int)($watermark['full_watermark'] ?? 0),
            'incremental_watermark' => (int)($watermark['incremental_watermark'] ?? 0),
            'build_status' => (string)($watermark['build_status'] ?? ''),
            'shard_fingerprint' => (string)($watermark['shard_fingerprint'] ?? ''),
            'row_version' => (int)($watermark['row_version'] ?? 0),
        ];
    }

    /**
     * @param array{
     *   db:array<string,mixed>,
     *   guard:DatabaseFingerprintGuard,
     *   fingerprint:string,
     *   database:string
     * } $target
     * @param array<string,mixed> $preflight
     */
    private function manifest(
        string $checkpointId,
        array $target,
        array $preflight,
    ): MigrationManifest {
        $scope = $preflight['scope'];

        return MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-shadow',
            'repo' => 'framework',
            'branch' => 'local',
            'commit' => 'mig-p3c-search',
            'connector_fingerprint' => $target['fingerprint'],
            'schema_fingerprints' => [
                'search_shard_schema' => \hash(
                    'sha256',
                    SearchShardSchemaCatalog::SCHEMA_VERSION,
                ),
            ],
            'row_counts' => [
                'product_search_projection' => (int)$preflight['source_document_count'],
                'search_shadow_query' => (int)$preflight['shadow_query_count'],
            ],
            'row_hashes' => [
                'product_search_projection' => (string)$preflight['source_document_hash'],
                'product_snapshot' => (string)$preflight['product_snapshot_hash'],
                'search_shadow_queries' => $this->hash($preflight['shadow_queries']),
                'search_alias_before' => $this->hash($preflight['alias_state']),
            ],
            'watermarks' => [
                'website_id' => (int)$scope['website_id'],
                'store_id' => (int)$scope['store_id'],
                'channel_id' => (int)$scope['channel_id'],
                'locale' => (string)$scope['locale'],
                'currency' => (string)$scope['currency'],
                'source_start' => (int)$preflight['source_watermark'],
                'alias_version' => (int)$preflight['alias_state']['version'],
            ],
            'backup_ref' => 'clone-full:' . $target['database'],
            'created_at' => \gmdate('c'),
        ]);
    }

    private function checkpoint(DatabaseFingerprintGuard $guard): MigrationCheckpointService
    {
        return $this->checkpointService ?? new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(),
        );
    }

    private function newCheckpointId(): string
    {
        return 'p3csearch-' . \gmdate('YmdHis') . '-'
            . \substr(\bin2hex(\random_bytes(3)), 0, 6);
    }

    private function requireCheckpointId(string $checkpointId): string
    {
        $checkpointId = \trim($checkpointId);
        if ($checkpointId === '') {
            throw new \RuntimeException(
                self::ERROR_CHECKPOINT . ': pass --checkpoint=ID',
            );
        }

        return $checkpointId;
    }

    /** @param array<string,mixed> $preflight @return array<string,mixed> */
    private function publicPreflight(array $preflight): array
    {
        unset($preflight['snapshot'], $preflight['shadow_queries']);

        return $preflight;
    }

    /**
     * @param list<array{event?:string,detail?:array<string,mixed>}> $journal
     * @return array<string,mixed>|null
     */
    private function lastEventDetail(array $journal, string $event): ?array
    {
        for ($index = \count($journal) - 1; $index >= 0; $index--) {
            $row = $journal[$index] ?? null;
            if (!\is_array($row) || (string)($row['event'] ?? '') !== $event) {
                continue;
            }

            return \is_array($row['detail'] ?? null) ? $row['detail'] : [];
        }

        return null;
    }

    /** @param list<array{event?:string}> $journal */
    private function eventIndex(array $journal, string $event): int
    {
        for ($index = \count($journal) - 1; $index >= 0; $index--) {
            if ((string)($journal[$index]['event'] ?? '') === $event) {
                return $index;
            }
        }

        return -1;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameEvidence(array $expected, array $actual): bool
    {
        foreach ([
            'website_id',
            'source_watermark',
            'full_watermark',
            'incremental_watermark',
            'active_generation',
            'build_generation',
            'build_status',
            'source_document_count',
            'index_document_count',
            'source_document_hash',
            'index_document_hash',
            'product_snapshot_hash',
            'shard_fingerprint',
            'shard_schema_version',
            'caught_up',
            'documents_equal',
        ] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function hash(mixed $value): string
    {
        return \hash('sha256', (string)\json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!\array_is_list($value)) {
            \ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function runtimeBuilder(): SearchIndexBuilder
    {
        return $this->builder ??= ObjectManager::getInstance(SearchIndexBuilder::class);
    }

    private function runtimeDirect(): ProductDirectCatalogReaderInterface
    {
        return $this->direct ??= ObjectManager::getInstance(
            ProductDirectCatalogReaderInterface::class,
        );
    }

    private function runtimeRollout(): SearchRolloutGate
    {
        return $this->rollout ??= new SearchRolloutGate(
            ConfigStore::forConnection(ConnectionFactory::getInstance()),
        );
    }

    private function runtimeAlias(): SearchAliasStore
    {
        return $this->alias ??= new SearchAliasStore(
            ObjectManager::getInstance(SearchServingAlias::class),
        );
    }

    private function runtimeDegrade(): SearchDegradeMarker
    {
        return $this->degrade ??= new SearchDegradeMarker(
            ObjectManager::getInstance(SearchDegradeMarkerStoreInterface::class),
        );
    }

    private function runtimeQuery(): SearchQueryService
    {
        return $this->query ??= new SearchQueryService(
            $this->runtimeBuilder()->store(),
            $this->runtimeBuilder()->registry(),
            $this->runtimeDirect(),
            $this->runtimeDegrade(),
            $this->runtimeRollout(),
            $this->runtimeAlias(),
        );
    }

    private function runtimeComparator(): SearchShadowComparator
    {
        return $this->comparator ??= new SearchShadowComparator(
            $this->runtimeQuery(),
            $this->runtimeDirect(),
        );
    }

    private function runtimeApplier(): SearchIndexIncrementalApplier
    {
        return $this->applier ??= new SearchIndexIncrementalApplier(
            $this->runtimeBuilder()->registry(),
            $this->runtimeBuilder()->store(),
            $this->runtimeBuilder()->source(),
        );
    }

    private function assertTesting(): void
    {
        if (!$this->memoryTarget) {
            throw new \LogicException('search_migration_seed_is_test_only');
        }
    }

    private function refreshTestingSnapshot(): void
    {
        $source = $this->runtimeBuilder()->source();
        if (!$source instanceof ArrayProductSearchProjectionSource) {
            throw new \LogicException('search_migration_testing_source_required');
        }
        $byWebsite = [];
        foreach ($this->memoryPublished as &$document) {
            $websiteId = (int)($document['website_id'] ?? 0);
            $document['entity_type'] = (string)($document['entity_type'] ?? 'product');
            $document['website_id'] = $websiteId;
            $document['website_code'] = \trim((string)($document['website_code'] ?? ''))
                ?: ($websiteId === 0 ? 'default' : 'website-' . $websiteId);
            $document['store_id'] = \max(1, (int)($document['store_id'] ?? 1));
            $document['store_code'] = \trim((string)($document['store_code'] ?? ''))
                ?: 'default';
            $document['channel_id'] = \max(1, (int)($document['channel_id'] ?? 1));
            $document['channel_code'] = \trim((string)($document['channel_code'] ?? ''))
                ?: 'default';
            $document['locale'] = (string)($document['locale'] ?? '');
            $document['currency'] = (string)($document['currency'] ?? '');
            $document['document_version'] = (int)(
                $document['document_version'] ?? $document['publish_version'] ?? 0
            );
            $document['status'] = (string)($document['status'] ?? 'published');
            $byWebsite[$websiteId][] = $document;
        }
        unset($document);
        foreach ($byWebsite as $websiteId => $documents) {
            $watermark = 0;
            foreach ($documents as $document) {
                $watermark = \max($watermark, (int)$document['document_version']);
            }
            $source->seedSnapshot($websiteId, $documents, $watermark);
        }
    }

    /** @param array<string,mixed> $event */
    private function applyTestingEventToPublished(array $event): void
    {
        $document = $event['document'] ?? null;
        if (!\is_array($document)) {
            return;
        }
        $websiteId = (int)($event['website_id'] ?? $document['website_id'] ?? 0);
        $document['website_id'] = $websiteId;
        $document['document_version'] = (int)(
            $document['document_version'] ?? $event['event_seq'] ?? 0
        );
        $entityType = (string)($document['entity_type'] ?? 'product');
        $entityId = (string)($document['entity_id'] ?? '');
        $storeId = (int)($document['store_id'] ?? 1);
        $channelId = (int)($document['channel_id'] ?? 1);
        $locale = (string)($document['locale'] ?? '');
        $currency = (string)($document['currency'] ?? '');
        foreach ($this->memoryPublished as $index => $current) {
            if ((int)($current['website_id'] ?? 0) === $websiteId
                && (string)($current['entity_type'] ?? 'product') === $entityType
                && (string)($current['entity_id'] ?? '') === $entityId
                && (int)($current['store_id'] ?? 1) === $storeId
                && (int)($current['channel_id'] ?? 1) === $channelId
                && (string)($current['locale'] ?? '') === $locale
                && (string)($current['currency'] ?? '') === $currency
            ) {
                $this->memoryPublished[$index] = $current + $document;
                foreach ($document as $key => $value) {
                    $this->memoryPublished[$index][$key] = $value;
                }

                return;
            }
        }
        $this->memoryPublished[] = $document;
    }
}
