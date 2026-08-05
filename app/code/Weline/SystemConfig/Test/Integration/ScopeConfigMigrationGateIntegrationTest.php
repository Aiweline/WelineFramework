<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;
use Weline\SystemConfig\Service\SystemConfigLockService;

/**
 * TEST-MIG-P1B-03, TEST-MIG-P1B-04, TEST-MIG-P1B-05 and TEST-MIG-P1B-06:
 * 只允许在登记的 mig_clone_* 上运行，并验证并发、继承失效与敏感值恢复。
 */
final class ScopeConfigMigrationGateIntegrationTest extends TestCase
{
    private const MODULE = 'Weline_MigP1bGate';
    private const AREA = SystemConfig::area_BACKEND;
    private const LOCALE = SystemConfig::LOCALE_DEFAULT;
    private const WEBSITE_SCOPE = 'migshop.default.default';
    private const STORE_SCOPE = 'migshop.main.default';
    private const OVERRIDE_SCOPE = 'migshop.outlet.default';

    private SystemConfig $config;
    private SystemConfigLockService $locks;
    private ScopeConfigCacheInvalidator $cacheInvalidator;

    public static function setUpBeforeClass(): void
    {
        $database = \trim((string)\getenv('WELINE_MIG_TEST_DATABASE'));
        if ($database === '') {
            self::markTestSkipped('WELINE_MIG_TEST_DATABASE is required');
        }

        $env = include BP . '/app/etc/env.php';
        $db = \is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
        if (!\is_array($db)) {
            self::fail('master database config is unavailable');
        }
        $db['database'] = $database;

        ObjectManager::clearInstances();
        ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ObjectManager::getInstance(SystemConfig::class);
        $this->locks = ObjectManager::getInstance(SystemConfigLockService::class);
        $this->cacheInvalidator = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        $this->deleteProbeRows();
    }

    protected function tearDown(): void
    {
        $this->deleteProbeRows();
        parent::tearDown();
    }

    public function testLockUnlockKeepsWeakSensitiveOverrideSuppressedUntilExplicitInheritance(): void
    {
        $key = 'mig_p1b/sensitive_lock';
        $parentSecret = 'secret-ref-parent-03';
        $childSecret = 'secret-ref-child-03';
        $this->save($key, $parentSecret, self::WEBSITE_SCOPE, ['sensitive_keys' => [$key]]);
        $this->save($key, $childSecret, self::STORE_SCOPE, ['sensitive_keys' => [$key]]);

        $locked = $this->locks->lockScope(
            self::MODULE,
            self::AREA,
            $key,
            self::WEBSITE_SCOPE,
            self::LOCALE,
        );
        self::assertTrue($locked['success']);
        self::assertCount(1, $locked['suppressed']);

        $childRow = $this->config->getScopedConfigRow(
            $key,
            self::MODULE,
            self::AREA,
            self::STORE_SCOPE,
            self::LOCALE,
        );
        self::assertTrue(SystemConfigLockService::isRowSuppressed($childRow));
        self::assertSame('***', $this->config->maskSensitiveRow($childRow)[SystemConfig::schema_fields_VALUE]);
        self::assertStringNotContainsString($childSecret, \json_encode(
            $this->config->maskSensitiveRow($childRow),
            \JSON_UNESCAPED_UNICODE,
        ) ?: '');

        $resolved = $this->config->resolveTypedConfig(
            $key,
            self::MODULE,
            self::AREA,
            ScopeIdentity::store(901, 'migshop', 'main', ScopeIdentity::MODE_NORMAL),
            self::LOCALE,
        );
        self::assertSame($parentSecret, $resolved->value);
        self::assertSame(self::WEBSITE_SCOPE, $resolved->source->storageScope);

        $unlocked = $this->locks->unlockScope(
            self::MODULE,
            self::AREA,
            $key,
            self::WEBSITE_SCOPE,
            self::LOCALE,
        );
        self::assertTrue($unlocked['success']);
        self::assertFalse($unlocked['children_auto_restored']);
        self::assertTrue(SystemConfigLockService::isRowSuppressed($this->config->getScopedConfigRow(
            $key,
            self::MODULE,
            self::AREA,
            self::STORE_SCOPE,
            self::LOCALE,
        )));

        self::assertTrue($this->config->deleteScopedConfig(
            $key,
            self::MODULE,
            self::AREA,
            self::STORE_SCOPE,
            self::LOCALE,
        ));
        self::assertNull($this->config->getScopedConfigRow(
            $key,
            self::MODULE,
            self::AREA,
            self::STORE_SCOPE,
            self::LOCALE,
        ));
    }

    public function testConcurrentExpectedVersionRejectsSecondAdministrator(): void
    {
        $key = 'mig_p1b/cas';
        $first = $this->save($key, 'v1', self::WEBSITE_SCOPE);
        self::assertTrue($first['success']);
        $row = $this->config->getScopedConfigRow(
            $key,
            self::MODULE,
            self::AREA,
            self::WEBSITE_SCOPE,
            self::LOCALE,
        );
        $baseVersion = (int)($row[SystemConfig::schema_fields_VERSION] ?? 0);

        $adminA = $this->save($key, 'admin-a', self::WEBSITE_SCOPE, [
            'base_versions' => [$key => $baseVersion],
            'actor_id' => 'admin-a',
        ]);
        self::assertTrue($adminA['success']);

        $adminB = $this->save($key, 'admin-b', self::WEBSITE_SCOPE, [
            'base_versions' => [$key => $baseVersion],
            'actor_id' => 'admin-b',
        ]);
        self::assertFalse($adminB['success']);
        self::assertSame('conflict', $adminB['status']);
        self::assertSame($baseVersion, $adminB['conflicts'][0]['expected_version']);

        $resolved = $this->config->resolveConfig(
            $key,
            self::MODULE,
            self::AREA,
            self::WEBSITE_SCOPE,
            self::LOCALE,
        );
        self::assertSame('admin-a', $resolved['value']);
    }

    public function testWebsiteCacheImpactInvalidatesInheritorAndSkipsActiveOverride(): void
    {
        $key = 'mig_p1b/cache';
        $this->save($key, 'website-v1', self::WEBSITE_SCOPE);
        $this->save($key, 'suppressed-placeholder', self::STORE_SCOPE, [
            'field_metadata' => [
                $key => [SystemConfigLockService::META_SUPPRESSED_BY => 701],
            ],
        ]);
        $this->save($key, 'outlet-override', self::OVERRIDE_SCOPE);

        $before = $this->cacheInvalidator->versionVectorFor(self::STORE_SCOPE);
        $plan = $this->cacheInvalidator->planImpact(
            self::MODULE,
            self::AREA,
            self::WEBSITE_SCOPE,
            self::LOCALE,
            [$key],
            $this->config,
        );
        self::assertContains(self::STORE_SCOPE, $plan['invalidate_scopes']);
        self::assertContains(self::OVERRIDE_SCOPE, $plan['skipped_override_scopes']);

        $this->save($key, 'website-v2', self::WEBSITE_SCOPE);
        $after = $this->cacheInvalidator->versionVectorFor(self::STORE_SCOPE);
        self::assertNotSame($before, $after);

        $inherited = $this->config->resolveConfig(
            $key,
            self::MODULE,
            self::AREA,
            self::STORE_SCOPE,
            self::LOCALE,
        );
        $override = $this->config->resolveConfig(
            $key,
            self::MODULE,
            self::AREA,
            self::OVERRIDE_SCOPE,
            self::LOCALE,
        );
        self::assertSame('website-v2', $inherited['value']);
        self::assertSame('outlet-override', $override['value']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function save(string $key, mixed $value, string $scope, array $options = []): array
    {
        return $this->config->saveScopeConfig(
            self::MODULE,
            self::AREA,
            [$key => $value],
            $scope,
            self::LOCALE,
            $options,
        );
    }

    private function deleteProbeRows(): void
    {
        $connector = $this->config->getConnection()->getConnector();
        $table = $connector->getTable($this->config->getOriginTableName());
        $stmt = $connector->getLink()->prepare(
            'DELETE FROM ' . $table . ' WHERE ' . SystemConfig::schema_fields_MODULE . ' = :module',
        );
        $stmt->execute([':module' => self::MODULE]);
    }
}
