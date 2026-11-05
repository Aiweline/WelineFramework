<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ConfigCacheInvalidationService;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;
use Weline\SystemConfig\Service\SecurityPolicyConfigGuard;
use Weline\SystemConfig\Service\SystemConfigLockService;

final class SystemConfigReadWriteCacheTest extends TestCase
{
    private const MODULE = 'Weline_Test';
    private const AREA = SystemConfig::area_BACKEND;
    private const SCOPE = 'shop.main.app';
    private InMemorySystemConfigRows $config;

    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('system_config')->clear();
        SystemConfig::clearProcessCache();
        $this->config = new InMemorySystemConfigRows();
        $model = $this->config;
        ObjectManager::setInstance(SystemConfig::class, $model);
        $this->config->rows = [$this->row('title', 'old', 1)];
    }

    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
        Context::leave();
    }

    public function testMapResolvedAndTypedReadersShareExactRows(): void
    {
        self::assertSame('old', $this->map()['title']);
        $reads = $this->config->moduleReads;
        self::assertSame('old', $this->config->resolveConfig('title', self::MODULE, self::AREA, self::SCOPE, 'default')['value']);
        self::assertSame('old', $this->config->resolveTypedConfig('title', self::MODULE, self::AREA, ScopeIdentity::channel(2, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL), 'default')->value);
        self::assertSame($reads, $this->config->moduleReads);
        self::assertSame(0, $this->config->singleReads);
    }

    public function testMissingKeyAfterCompleteModuleSnapshotSkipsExactReads(): void
    {
        self::assertSame('old', $this->map()['title']);
        $result = $this->config->resolveConfig('missing', self::MODULE, self::AREA, self::SCOPE, 'default', 'fallback');

        self::assertFalse($result['found']);
        self::assertSame('fallback', $result['value']);
        self::assertSame(0, $this->config->singleReads, 'a complete DB module snapshot must prove absence');
    }

    public function testWriteGenerationDisablesSnapshotAbsenceAuthority(): void
    {
        $this->map();
        $this->config->rows[] = $this->row('added', 'added', 1);
        $this->config->testConnection = $this->createStub(ConnectionFactory::class);
        $transactions = $this->createStub(TransactionCoordinatorInterface::class);
        $transactions->method('isActive')->willReturn(true);
        ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
        $guard = (new \ReflectionClass(SecurityPolicyConfigGuard::class))->newInstanceWithoutConstructor();
        ObjectManager::setInstance(SecurityPolicyConfigGuard::class, $guard);

        $save = $this->config->saveScopeConfig(
            self::MODULE,
            self::AREA,
            ['title' => 'edit'],
            self::SCOPE,
            'default',
            ['base_versions' => ['title' => 0]],
        );
        self::assertSame('conflict', $save['status']);

        $result = $this->config->resolveConfig('added', self::MODULE, self::AREA, self::SCOPE, 'default');
        self::assertTrue($result['found']);
        self::assertSame('added', $result['value']);
        self::assertGreaterThan(0, $this->config->singleReads);
    }

    public function testWarmCompleteSnapshotProvesAbsenceAcrossRequestBoundary(): void
    {
        self::assertSame('old', $this->map()['title']);
        $moduleReads = $this->config->moduleReads;

        // Simulate the next request on a warm WLS worker: request caches are
        // gone, the process-level module snapshot remains under the same
        // version vector.
        RequestContext::cleanup();

        $result = $this->config->resolveConfig('missing', self::MODULE, self::AREA, self::SCOPE, 'default', 'fallback');

        self::assertFalse($result['found']);
        self::assertSame('fallback', $result['value']);
        self::assertSame($moduleReads, $this->config->moduleReads, 'warm snapshot must serve the whole module');
        self::assertSame(0, $this->config->singleReads, 'a complete non-empty cache snapshot must prove absence');
    }

    public function testEmptySnapshotStillFallsBackToExactRead(): void
    {
        $this->config->rows = [];
        self::assertSame([], $this->config->getConfigByModule(self::MODULE, self::AREA, self::SCOPE, 'default'));

        RequestContext::cleanup();

        // A row inserted by another worker before invalidation reaches this
        // process must not be hidden by the empty (negative) snapshot.
        $this->config->rows = [$this->row('late', 'late', 1)];
        $result = $this->config->resolveConfig('late', self::MODULE, self::AREA, self::SCOPE, 'default');

        self::assertTrue($result['found']);
        self::assertSame('late', $result['value']);
        self::assertGreaterThan(0, $this->config->singleReads);
    }

    public function testModuleBatchReadsScopeAndLocaleChainOnce(): void
    {
        $this->config->rows = [
            $this->row('title', 'app-default', 1),
            array_replace($this->row('parent_only', 'parent', 1), ['scope' => 'shop.main.default']),
            array_replace($this->row('en_only', 'en', 1), ['locale' => 'en_US']),
        ];

        $map = $this->config->getConfigMapByModule(self::MODULE, self::AREA, self::SCOPE, 'en_US');

        self::assertSame(1, $this->config->moduleReads, 'scope/locale chain must use one batch read');
        self::assertSame('en', $map['en_only']);
        self::assertSame('app-default', $map['title']);
        self::assertSame('parent', $map['parent_only']);
    }

    public function testModuleBatchSkipsInactiveAndSuppressedRows(): void
    {
        $this->config->rows = [
            array_replace($this->row('inactive', 'inactive', 1), ['is_active' => 0]),
            array_replace($this->row('suppressed', 'suppressed', 1), [
                'metadata' => json_encode([SystemConfigLockService::META_SUPPRESSED_BY => 7]),
            ]),
            $this->row('ok', 'ok', 1),
        ];

        $map = $this->config->getConfigMapByModule(self::MODULE, self::AREA, self::SCOPE, 'default');

        self::assertSame(1, $this->config->moduleReads);
        self::assertArrayNotHasKey('inactive', $map);
        self::assertArrayNotHasKey('suppressed', $map);
        self::assertSame('ok', $map['ok']);
    }

    public function testModuleBatchRequestCacheServesRepeatedReads(): void
    {
        $this->config->rows = [$this->row('title', 'cached', 1)];

        self::assertSame('cached', $this->config->getConfigMapByModule(self::MODULE, self::AREA, self::SCOPE, 'default')['title']);
        self::assertSame('cached', $this->config->getConfigMapByModule(self::MODULE, self::AREA, self::SCOPE, 'default')['title']);
        self::assertSame(1, $this->config->moduleReads);
    }

    public function testTypedReadBatchesEveryScopeAndLocalePairOnce(): void
    {
        $this->config->rows = [
            $this->row('title', 'app', 1),
            array_replace($this->row('parent_only', 'parent', 1), ['scope' => 'shop.main.default']),
        ];

        $result = $this->config->resolveTypedConfig(
            'title',
            self::MODULE,
            self::AREA,
            ScopeIdentity::channel(2, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL),
            'default',
        );

        self::assertSame(1, $this->config->moduleReads, 'typed scope chain must use one batch read');
        self::assertSame('app', $result->value);
    }

    public function testExactAdministrativeReadIgnoresEarlierReaderSnapshot(): void
    {
        $this->map();
        $this->config->rows = [$this->row('title', 'new', 2)];
        $row = $this->config->getScopedConfigRow('title', self::MODULE, self::AREA, self::SCOPE, 'default');
        self::assertSame(2, $row[SystemConfig::schema_fields_VERSION]);
        self::assertSame(1, $this->config->singleReads);
    }

    public function testTypedReadPreservesSuppressionLocaleFallbackAndMissingDefault(): void
    {
        $this->config->rows = [
            array_replace($this->row('title', 'suppressed', 3), [
                'locale' => 'en_US',
                'metadata' => json_encode([SystemConfigLockService::META_SUPPRESSED_BY => 7]),
            ]),
            array_replace($this->row('title', 'inherited', 2), ['scope' => 'shop.main.default']),
        ];
        $identity = ScopeIdentity::channel(2, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        $result = $this->config->resolveTypedConfig('title', self::MODULE, self::AREA, $identity, 'en_US');
        self::assertSame('inherited', $result->value);
        self::assertSame('shop.main.default', $result->source->storageScope);
        self::assertSame('fallback', $this->config->resolveTypedConfig('missing', self::MODULE, self::AREA, $identity, 'en_US', 'fallback')->value);
    }

    public function testTypedReadKeepsExplicitNullFalseAndZero(): void
    {
        $this->config->rows = [
            array_replace($this->row('null_value', '', 1), ['value_type' => SystemConfig::VALUE_TYPE_NULL]),
            array_replace($this->row('false_value', '0', 1), ['value_type' => SystemConfig::VALUE_TYPE_BOOL]),
            array_replace($this->row('zero_value', '0', 1), ['value_type' => SystemConfig::VALUE_TYPE_INT]),
        ];
        $identity = ScopeIdentity::channel(2, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        foreach (['null_value' => null, 'false_value' => false, 'zero_value' => 0] as $key => $expected) {
            $result = $this->config->resolveTypedConfig($key, self::MODULE, self::AREA, $identity, 'default', 'fallback');
            self::assertTrue($result->found());
            self::assertSame($expected, $result->value);
        }
    }

    public function testSaveConflictReportsCurrentDatabaseVersionAfterEarlierRead(): void
    {
        $this->map();
        $this->config->rows = [$this->row('title', 'new', 2)];
        $this->config->testConnection = $this->createStub(ConnectionFactory::class);
        $transactions = $this->createStub(TransactionCoordinatorInterface::class);
        $transactions->method('isActive')->willReturn(true);
        ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
        // An unrelated module returns before the guard needs policy dependencies.
        $guard = (new \ReflectionClass(SecurityPolicyConfigGuard::class))->newInstanceWithoutConstructor();
        ObjectManager::setInstance(SecurityPolicyConfigGuard::class, $guard);
        $result = $this->config->saveScopeConfig(self::MODULE, self::AREA, ['title' => 'edit'], self::SCOPE, 'default', ['base_versions' => ['title' => 0]]);
        self::assertSame('conflict', $result['status']);
        self::assertSame(2, $result['conflicts'][0]['current_version']);
        self::assertSame('new', $this->config->rows[0][SystemConfig::schema_fields_VALUE]);
    }

    public function testCommittedInvalidationClearsLocalSnapshotsAndReadRebuilds(): void
    {
        $this->map();
        $vector = (new ScopeConfigCacheInvalidator())->versionVectorFor(self::SCOPE);
        $mapRequestKey = implode(':', ['system_config', 'module_map', self::AREA, self::MODULE, self::SCOPE, 'default', $vector]);
        self::assertTrue(RequestContext::has($mapRequestKey));
        $this->config->rows = [$this->row('title', 'new', 2)];
        // Shared-pool delete is Framework cache_ops afterCommit; this service only
        // clears request-local snapshots and bumps the scope version vector.
        $service = (new \ReflectionClass(ConfigCacheInvalidationService::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($service, 'invalidateNow'))->invoke($service, self::MODULE, self::AREA, self::SCOPE, 'default', ['title'], [], []);
        self::assertFalse(RequestContext::has($mapRequestKey));
        self::assertSame('new', $this->map()['title']);
    }

    public function testCommittedInvalidationAdvancesCrossProcessSnapshotGeneration(): void
    {
        $key = $this->snapshotGenerationKey();
        $before = $this->sharedSnapshotGeneration();
        $service = (new \ReflectionClass(ConfigCacheInvalidationService::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($service, 'invalidateNow'))->invoke($service, self::MODULE, self::AREA, self::SCOPE, 'default', ['title'], [], []);

        self::assertSame(
            $before + 1,
            $this->sharedSnapshotGeneration(),
            'write path must advance the cross-process table snapshot generation',
        );
    }

    public function testProcessTableSnapshotServesWithoutDatabaseRead(): void
    {
        // 对照组：代次未变时，整表快照就是权威，请求期一条库读都不该发生。
        $this->config->rows = [];
        $this->seedProcessTableSnapshot([$this->row('title', 'from-snapshot', 1)]);

        $rows = $this->config->getConfigByModule(self::MODULE, self::AREA, self::SCOPE, 'default');

        self::assertSame('from-snapshot', $rows[0][SystemConfig::schema_fields_VALUE] ?? null);
        self::assertSame(0, $this->config->moduleReads, 'a valid table snapshot must answer without SQL');
    }

    public function testExternalWorkerWriteInvalidatesProcessTableSnapshot(): void
    {
        // A 进程写、B 进程读：写侧只推进**共享**代次，B 的静态快照原封不动。
        // 没有代次比对时，B 会拿旧快照证明“配置没变”（配置改了不生效）。
        $this->config->rows = [$this->row('title', 'from-db', 2)];
        $this->seedProcessTableSnapshot([$this->row('title', 'from-snapshot', 1)]);
        \w_cache('system_config')->setCustom(
            $this->snapshotGenerationKey(),
            $this->sharedSnapshotGeneration() + 1,
        );

        $rows = $this->config->getConfigByModule(self::MODULE, self::AREA, self::SCOPE, 'default');

        self::assertSame('from-db', $rows[0][SystemConfig::schema_fields_VALUE] ?? null);
        self::assertGreaterThan(0, $this->config->moduleReads, 'a stale snapshot must not answer after an external write');
    }

    public function testCompleteTableSnapshotSkipsExactProbesForMultiCandidateScopeChain(): void
    {
        // 复刻 Weline_Seo 商家信息读取：多候选 scope 链（__store__ → default）+ 该模块
        // 在库里没有配置。模块级 marker 只覆盖单组 scope/locale，命中不了这种调用形状，
        // 整表快照有效时必须由快照证明“缺失”，否则每个 key 会逐 scope×locale 探针。
        $this->config->rows = [];
        $this->seedProcessTableSnapshot([$this->row('title', 'from-snapshot', 1)]);

        $first = $this->config->resolveConfig('seo/missing', self::MODULE, self::AREA, 'default.__store__.default', 'default', 'fallback');
        $second = $this->config->resolveConfig('seo/missing', self::MODULE, self::AREA, 'default.default.default', 'default', 'fallback');

        self::assertFalse($first['found']);
        self::assertFalse($second['found']);
        self::assertSame('fallback', $first['value']);
        self::assertSame(0, $this->config->singleReads, 'a valid table snapshot must prove absence across every scope candidate');
        self::assertSame(0, $this->config->moduleReads, 'a valid table snapshot must answer without SQL');
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedProcessTableSnapshot(array $rows): void
    {
        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row['area']][$row['module']][$row['scope']][$row['locale']][] = $row;
        }
        $snapshotProperty = new \ReflectionProperty(SystemConfig::class, 'tableSnapshot');
        $generationProperty = new \ReflectionProperty(SystemConfig::class, 'tableSnapshotGeneration');
        $snapshotProperty->setValue(null, $snapshot);
        $generationProperty->setValue(null, $this->sharedSnapshotGeneration());
    }

    private function snapshotGenerationKey(): string
    {
        return (string)(new \ReflectionClassConstant(SystemConfig::class, 'TABLE_SNAPSHOT_GENERATION_KEY'))->getValue();
    }

    private function sharedSnapshotGeneration(): int
    {
        return (int)(\w_cache('system_config')->getCustom($this->snapshotGenerationKey()) ?? 0);
    }

    public function testBuildCacheOpsEmitsDualPoolKeysForFrameworkObserver(): void
    {
        $ops = ObjectManager::getInstance(ConfigCacheInvalidationService::class)->buildCacheOps(
            self::MODULE,
            self::AREA,
            self::SCOPE,
            'default',
            ['title'],
            [],
            [],
        );
        self::assertSame(['system_config', 'database'], array_column($ops, 'pool'));
        self::assertNotEmpty($ops[0]['keys']);
        self::assertSame($ops[0]['keys'], $ops[1]['keys']);
    }

    public function testScopedRowReaderUsesRequestMemo(): void
    {
        $this->config->rows = [$this->row('title', 'old', 1)];

        $first = $this->config->getScopedConfigRow('title', self::MODULE, self::AREA, self::SCOPE, 'default');
        $readsAfterFirst = $this->config->singleReads;
        $second = $this->config->getScopedConfigRow('title', self::MODULE, self::AREA, self::SCOPE, 'default');

        self::assertSame('old', $first[SystemConfig::schema_fields_VALUE] ?? null);
        self::assertSame($first, $second);
        self::assertSame(1, $readsAfterFirst);
        self::assertSame(
            $readsAfterFirst,
            $this->config->singleReads,
            'repeated exact-row read of the same key must come from the request memo',
        );
    }

    public function testScopedRowRequestMemoIsInvalidatedByWrite(): void
    {
        // Exact-row reader: no module snapshot involved, so the request memo and
        // its read count are directly observable.
        $first = $this->config->getScopedConfigRow('late', self::MODULE, self::AREA, self::SCOPE, 'default');
        self::assertNull($first);
        $readsAfterFirst = $this->config->singleReads;
        self::assertGreaterThan(0, $readsAfterFirst);

        // Row appears without an in-request write yet: the negative memo must win.
        $this->config->rows = [$this->row('late', 'late', 1)];
        $second = $this->config->getScopedConfigRow('late', self::MODULE, self::AREA, self::SCOPE, 'default');
        self::assertNull($second);
        self::assertSame($readsAfterFirst, $this->config->singleReads, 'repeated exact-row read must come from the request memo');

        // A write in the same request bumps the mutation generation (this write
        // short-circuits as a conflict, which is enough to invalidate the memo).
        $this->config->testConnection = $this->createStub(ConnectionFactory::class);
        $transactions = $this->createStub(TransactionCoordinatorInterface::class);
        $transactions->method('isActive')->willReturn(true);
        ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
        $guard = (new \ReflectionClass(SecurityPolicyConfigGuard::class))->newInstanceWithoutConstructor();
        ObjectManager::setInstance(SecurityPolicyConfigGuard::class, $guard);
        $save = $this->config->saveScopeConfig(
            self::MODULE,
            self::AREA,
            ['late' => 'late'],
            self::SCOPE,
            'default',
            ['base_versions' => ['late' => 0]],
        );
        self::assertSame('conflict', $save['status']);

        // Read-after-write: the memo captured before the write must not hide the row.
        $third = $this->config->getScopedConfigRow('late', self::MODULE, self::AREA, self::SCOPE, 'default');
        self::assertIsArray($third);
        self::assertSame('late', $third[SystemConfig::schema_fields_VALUE] ?? null);
        self::assertGreaterThan($readsAfterFirst, $this->config->singleReads);
    }

    public function testMutationGenerationUsesRequestSharedCarrier(): void
    {
        // E1: with a request identity, the mutation generation must live on the
        // request-shared carrier so every Fiber copy of this request sees the same
        // invalidation point (Context-local generation is per Fiber).
        RequestContext::init();
        RequestContext::setId('sysconfig-shared-generation-test');
        try {
            $key = (new \ReflectionClassConstant(SystemConfig::class, 'MUTATION_GENERATION_KEY'))->getValue();
            $before = RequestContext::sharedGeneration($key);

            $this->config->testConnection = $this->createStub(ConnectionFactory::class);
            $transactions = $this->createStub(TransactionCoordinatorInterface::class);
            $transactions->method('isActive')->willReturn(true);
            ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
            $guard = (new \ReflectionClass(SecurityPolicyConfigGuard::class))->newInstanceWithoutConstructor();
            ObjectManager::setInstance(SecurityPolicyConfigGuard::class, $guard);
            $this->config->saveScopeConfig(
                self::MODULE,
                self::AREA,
                ['title' => 'edit'],
                self::SCOPE,
                'default',
                ['base_versions' => ['title' => 0]],
            );

            self::assertSame($before + 1, RequestContext::sharedGeneration($key));
        } finally {
            RequestContext::cleanup();
        }
    }


    public function testKillSwitchDisablesScopedRowRequestMemo(): void
    {
        // C2 coverage: `request_memo.disabled` equivalent must make the
        // single-value memo inert — every read falls through to the row loader.
        $this->config->rows = [$this->row('title', 'old', 1)];
        RequestContext::overrideSharedMemoEnabled(false);
        try {
            $this->config->singleReads = 0;
            $first = $this->config->getScopedConfigRow('title', self::MODULE, self::AREA, self::SCOPE, 'default');
            $second = $this->config->getScopedConfigRow('title', self::MODULE, self::AREA, self::SCOPE, 'default');
            self::assertSame('old', $first[SystemConfig::schema_fields_VALUE] ?? null);
            self::assertSame($first, $second);
            self::assertSame(2, $this->config->singleReads, 'kill switch off => two authoritative reads');
        } finally {
            RequestContext::overrideSharedMemoEnabled(null);
        }
    }

    private function map(): array
    {
        return $this->config->getConfigMapByModule(self::MODULE, self::AREA, self::SCOPE, 'default');
    }

    private function row(string $key, string $value, int $version): array
    {
        return ['key' => $key, SystemConfig::schema_fields_VALUE => $value, 'version' => $version, 'module' => self::MODULE, 'area' => self::AREA, 'scope' => self::SCOPE, 'locale' => 'default', 'value_type' => 'string', 'is_active' => 1];
    }
}

final class InMemorySystemConfigRows extends SystemConfig
{
    public array $rows = [];
    public int $moduleReads = 0;
    public int $singleReads = 0;
    public ?ConnectionFactory $testConnection = null;

    public function __construct()
    {
        $this->_cache = \w_cache('system_config');
    }

    public function getConnection()
    {
        return $this->testConnection ?? throw new \LogicException('Live DB access is forbidden in this test.');
    }

    public function listRowsForKey(string $module, string $area, string $key): array
    {
        return array_values(array_filter($this->rows, static fn(array $row): bool => $row['module'] === $module && $row['area'] === $area && $row['key'] === $key));
    }

    protected function loadConfigRowsByModule(string $module, string $area, string $scope, string $locale): array
    {
        ++$this->moduleReads;
        return array_values(array_filter($this->rows, static fn(array $row): bool => $row['module'] === $module && $row['area'] === $area && $row['scope'] === $scope && $row['locale'] === $locale));
    }

    protected function loadConfigRowsByModuleScopes(string $module, string $area, array $scopes, array $locales): array
    {
        ++$this->moduleReads;

        return array_values(array_filter($this->rows, static fn(array $row): bool => $row['module'] === $module
            && $row['area'] === $area
            && in_array($row['scope'], $scopes, true)
            && in_array($row['locale'], $locales, true)));
    }

    protected function loadSingleConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        ++$this->singleReads;
        foreach ($this->rows as $row) {
            if ($row['key'] === $key && $row['module'] === $module && $row['area'] === $area && $row['scope'] === $scope && $row['locale'] === $locale) {
                return $row;
            }
        }
        return null;
    }

    protected function dispatchConfigGetEvent(string $key, string $module, string $area, mixed $value, string $scope, string $locale): mixed
    {
        return $value;
    }
}
