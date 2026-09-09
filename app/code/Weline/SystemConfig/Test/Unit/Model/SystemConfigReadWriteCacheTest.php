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

    public function testCommittedInvalidationDeletesExactSnapshotsAndReadRebuilds(): void
    {
        $this->map();
        $vector = (new ScopeConfigCacheInvalidator())->versionVectorFor(self::SCOPE);
        $requestKey = implode(':', ['system_config', 'module_exact_rows', self::AREA, self::MODULE, self::SCOPE, 'default', $vector]);
        $sharedKey = 'system_config_exact_rows_' . sha1(implode('|', [self::AREA, self::MODULE, self::SCOPE, 'default', $vector]));
        self::assertTrue(RequestContext::has($requestKey));
        self::assertTrue(\w_cache('system_config')->has($sharedKey));
        $this->config->rows = [$this->row('title', 'new', 2)];
        // Exercise the existing after-commit body without a live DB transaction.
        $service = (new \ReflectionClass(ConfigCacheInvalidationService::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($service, 'invalidateNow'))->invoke($service, self::MODULE, self::AREA, self::SCOPE, 'default', ['title'], [], []);
        self::assertFalse(RequestContext::has($requestKey));
        self::assertFalse(\w_cache('system_config')->has($sharedKey));
        self::assertSame('new', $this->map()['title']);
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
