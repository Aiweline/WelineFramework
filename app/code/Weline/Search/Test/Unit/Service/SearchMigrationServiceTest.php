<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\SearchAliasStore;
use Weline\Search\Service\SearchMigrationService;
use Weline\Search\Service\SearchQueryService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TASK-MIG-P3C: checkpoint, catch-up, fresh verify, alias CAS and rollback.
 */
final class SearchMigrationServiceTest extends TestCase
{
    private string $journalDir;
    private SearchMigrationService $service;

    protected function setUp(): void
    {
        $this->journalDir = \sys_get_temp_dir() . '/search_migration_test_' . \uniqid('', true);
        $this->service = SearchMigrationService::forTesting($this->journalDir);
        $this->seedAlignedWindow($this->service);
    }

    public function testApplyRequiresIsolatedClone(): void
    {
        $preflight = $this->preflight(
            $this->service,
            $this->cloneDb('mig_clone_p3csearch_preflight'),
        );
        self::assertTrue($preflight['ok']);
        self::assertTrue($preflight['apply_ready']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $preflight['rollout']['mode']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $preflight['alias_state']['alias']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SearchMigrationService::ERROR_SHARED_DB);
        $this->apply($this->service, null);
    }

    public function testFullPlusIncrementalRequiresFreshVerifyBeforeAliasCutover(): void
    {
        $target = $this->cloneDb('mig_clone_p3csearch_unit');
        $apply = $this->apply($this->service, $target);

        self::assertTrue($apply['ok'], \json_encode($apply));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $apply['mode']);
        self::assertFalse($apply['allowlist_ready']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $apply['alias_state']['alias']);
        self::assertSame(0, $apply['report']['unclassified_diff_count']);
        self::assertTrue($apply['report']['conserved']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $apply['report']['report_hash']);
        self::assertTrue($apply['evidence']['caught_up']);
        self::assertTrue($apply['evidence']['documents_equal']);
        self::assertSame(2, $apply['evidence']['source_watermark']);
        self::assertSame(2, $apply['evidence']['full_watermark']);
        self::assertSame(2, $apply['evidence']['incremental_watermark']);

        $fresh = $this->freshService();
        $verify = $fresh->verify($target, $apply['checkpoint_id']);
        self::assertTrue($verify['ok'], \json_encode($verify['diffs']));
        self::assertTrue($verify['verified_for_allowlist']);
        self::assertSame('shadow', $verify['serving_state']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $verify['alias_state']['alias']);

        $allowlist = $fresh->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            1,
            1,
        );
        self::assertTrue($allowlist['ok'], \json_encode($allowlist));
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame([[
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 1,
        ]], $allowlist['allowlist']);
        self::assertSame(SearchAliasStore::ALIAS_INDEX, $allowlist['alias_state']['alias']);
        self::assertSame(
            $apply['evidence']['active_generation'],
            $allowlist['alias_state']['generation'],
        );

        $search = $fresh->query()->search($this->query('alpha'));
        self::assertTrue($search['ok']);
        self::assertSame(SearchQueryService::SOURCE_INDEX, $search['source']);
        self::assertSame(1, $search['hit_count']);
        self::assertSame('Alpha Plus', $search['hits'][0]['title']);

        $idempotent = $fresh->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            1,
            1,
        );
        self::assertTrue($idempotent['ok']);
        self::assertTrue($idempotent['idempotent']);
    }

    public function testShadowPayloadMismatchFailsClosedAndKeepsDirectAlias(): void
    {
        $this->service->seedDirect([
            'entity_type' => 'product',
            'entity_id' => '1',
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 1,
            'locale' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'title' => 'Tampered Direct Title',
            'sku' => 'ALPHA',
            'status' => 'published',
            'document_version' => 2,
        ]);

        $result = $this->apply(
            $this->service,
            $this->cloneDb('mig_clone_p3csearch_diff'),
        );

        self::assertFalse($result['ok']);
        self::assertSame(SearchMigrationService::ERROR_SHADOW_DIFF, $result['error']);
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $result['mode']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $result['alias_state']['alias']);
        self::assertSame('product_direct', $result['fallback']);
        self::assertGreaterThan(0, $result['report']['unclassified_diff_count']);
        self::assertSame('hit_payload_mismatch', $result['report']['diffs'][0]['code']);
    }

    public function testAliasCasConflictRetainsPreviousServingGeneration(): void
    {
        $target = $this->cloneDb('mig_clone_p3csearch_cas');
        $apply = $this->apply($this->service, $target);
        self::assertTrue($apply['ok']);
        $fresh = $this->freshService();
        $fresh->alias()->forceConflictNextCas();
        $before = $fresh->alias()->state(0);

        $result = $fresh->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            1,
            1,
        );

        self::assertFalse($result['ok']);
        self::assertSame(SearchMigrationService::ERROR_ALIAS_CAS, $result['error']);
        self::assertTrue($result['previous_alias_retained']);
        self::assertSame($before['alias'], $result['alias_state']['alias']);
        self::assertSame($before['generation'], $result['alias_state']['generation']);
        self::assertSame($before['version'], $fresh->alias()->version(0));
        self::assertSame(
            CommerceRolloutGateInterface::MODE_SHADOW,
            $fresh->rollout()->mode(SearchMigrationService::CAPABILITY),
        );
    }

    public function testRollbackIsPersistentIdempotentAndRetainsIndexFacts(): void
    {
        $target = $this->cloneDb('mig_clone_p3csearch_rollback');
        $apply = $this->apply($this->service, $target);
        $fresh = $this->freshService();
        $allowlist = $fresh->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            1,
            1,
        );
        self::assertTrue($allowlist['ok']);

        $rollback = $fresh->rollbackToModeOff($target, $apply['checkpoint_id']);
        self::assertTrue($rollback['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $rollback['mode']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $rollback['alias_state']['alias']);
        self::assertTrue($rollback['index_facts_retained']);
        self::assertTrue($rollback['checkpoint_retained']);

        $generation = $fresh->builder()->store()->watermark(0)['active_generation'];
        self::assertGreaterThanOrEqual(1, (int)$generation);

        $again = $this->freshService()->rollbackToModeOff(
            $target,
            $apply['checkpoint_id'],
        );
        self::assertTrue($again['ok']);
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $again['alias_state']['alias']);

        $direct = $fresh->query()->search($this->query('alpha'));
        self::assertTrue($direct['ok']);
        self::assertSame(SearchQueryService::SOURCE_DIRECT, $direct['source']);
        self::assertFalse($direct['degraded']);
    }

    public function testApplyWithoutSamplesIsRejectedWithoutChangingServingState(): void
    {
        $empty = SearchMigrationService::forTesting();
        $result = $this->apply(
            $empty,
            $this->cloneDb('mig_clone_p3csearch_empty'),
        );

        self::assertFalse($result['ok']);
        self::assertSame(SearchMigrationService::ERROR_NO_SAMPLE, $result['error']);
        self::assertSame(
            CommerceRolloutGateInterface::MODE_OFF,
            $empty->rollout()->mode(SearchMigrationService::CAPABILITY),
        );
        self::assertSame(SearchAliasStore::ALIAS_DIRECT, $empty->alias()->activeAlias(0));
    }

    private function freshService(): SearchMigrationService
    {
        return SearchMigrationService::forTesting(
            $this->journalDir,
            $this->service->builder(),
            $this->service->direct(),
            $this->service->rollout(),
            $this->service->alias(),
            $this->service->query()->degrade(),
        );
    }

    private function seedAlignedWindow(SearchMigrationService $service): void
    {
        $base = [
            'entity_type' => 'product',
            'entity_id' => '1',
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 1,
            'locale' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'sku' => 'ALPHA',
            'status' => 'published',
        ];

        $service->seedPublished($base + [
            'title' => 'Alpha',
            'document_version' => 1,
        ]);
        $service->seedIncremental([
            'website_id' => 0,
            'idempotency_key' => 'mig-p3c-evt-2',
            'event_seq' => 2,
            'target_type' => 'product',
            'target_id' => 1,
            'document' => $base + [
                'title' => 'Alpha Plus',
                'document_version' => 2,
            ],
        ]);
        $service->seedDirect($base + [
            'title' => 'Alpha Plus',
            'document_version' => 2,
        ]);
        $service->seedShadowQuery($this->query('alpha'));
    }

    /** @return array<string,mixed> */
    private function preflight(SearchMigrationService $service, ?array $target): array
    {
        return $service->preflight($target, 0, 1, 1, 'zh_Hans_CN', 'CNY');
    }

    /** @return array<string,mixed> */
    private function apply(SearchMigrationService $service, ?array $target): array
    {
        return $service->apply($target, 0, 1, 1, 'zh_Hans_CN', 'CNY');
    }

    /**
     * @return array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string,q:string}
     */
    private function query(string $q = ''): array
    {
        return [
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 1,
            'locale' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'q' => $q,
        ];
    }

    /**
     * @return array{type:string,hostname:string,hostport:string,database:string,username:string}
     */
    private function cloneDb(string $database): array
    {
        return [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => $database,
            'username' => 'weline',
        ];
    }
}
