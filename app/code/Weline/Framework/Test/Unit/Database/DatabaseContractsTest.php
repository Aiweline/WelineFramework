<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use PDO as PDO;
use Weline\Framework\Console\Console\Mig\Foundation;
use Weline\Framework\Database\Compiler\Dialect\DialectInterface;
use Weline\Framework\Database\Compiler\Dialect\MysqlDialect;
use Weline\Framework\Database\Compiler\Dialect\PgsqlDialect;
use Weline\Framework\Database\Compiler\Dialect\SqliteDialect;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\CreatesTableFromSchemaTrait;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Migration\MigrationCloneHandle;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneRegistry;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\TimestampColumnOriginAuditor;
use Weline\Framework\Database\Schema\TimestampColumnOriginMarkers;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Test\Unit\Database\Schema\Fixture\FrameworkBaseTimestampFixtureModel;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * Aggregated contract suite (merged from 9 *ContractTest files).
 * Observe one overall pass/fail via testAllDatabaseContractsPass().
 */
final class DatabaseContractsTest extends TestCase
{
    public function testAllDatabaseContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AbstractModelUpdateMerge::testCheckUpdateOrInsertMergesChangedFieldsWithoutArrayWipe' => function () {
            $this->assert_AbstractModelUpdateMerge_testCheckUpdateOrInsertMergesChangedFieldsWithoutArrayWipe();
        },
            'DialectPortableExpressions::testDialectExposesPortableHelpers' => function () {
            $this->assert_DialectPortableExpressions_testDialectExposesPortableHelpers();
        },
            'DialectPortableExpressions::testConnectorsPublishSqlDialect' => function () {
            $this->assert_DialectPortableExpressions_testConnectorsPublishSqlDialect();
        },
            'DialectPortableExpressions::testBusinessInventoryNoLongerOwnsDialectKeywords' => function () {
            $this->assert_DialectPortableExpressions_testBusinessInventoryNoLongerOwnsDialectKeywords();
        },
            'DeclarativeSchemaConnector::testEverySupportedConnectorPublishesDeclarativeSchemaCreation' => function () {
            $this->assert_DeclarativeSchemaConnector_testEverySupportedConnectorPublishesDeclarativeSchemaCreation();
        },
            'DeclarativeSchemaConnector::testUnavailableDriversFailWithoutEnteringTranslationBootstrap' => function () {
            $this->assert_DeclarativeSchemaConnector_testUnavailableDriversFailWithoutEnteringTranslationBootstrap();
        },
            'DeclarativeSchemaConnector::testUnavailableDriverBranchesAreLocalizationIndependent' => function () {
            $this->assert_DeclarativeSchemaConnector_testUnavailableDriverBranchesAreLocalizationIndependent();
        },
            'MigrationFoundation::testFoundationCliFailureReturnsNonZeroStatus' => function () {
            $this->assert_MigrationFoundation_testFoundationCliFailureReturnsNonZeroStatus();
        },
            'MigrationFoundation::testSharedWelineDatabaseIsHardRejected' => function () {
            $this->assert_MigrationFoundation_testSharedWelineDatabaseIsHardRejected();
        },
            'MigrationFoundation::testCloneDatabaseNameIsAcceptedWhenAllowlisted' => function () {
            $this->assert_MigrationFoundation_testCloneDatabaseNameIsAcceptedWhenAllowlisted();
        },
            'MigrationFoundation::testTamperedManifestRejectsApplyWithZeroBusinessWrite' => function () {
            $this->assert_MigrationFoundation_testTamperedManifestRejectsApplyWithZeroBusinessWrite();
        },
            'MigrationFoundation::testValidManifestApplyGuardAppendsJournal' => function () {
            $this->assert_MigrationFoundation_testValidManifestApplyGuardAppendsJournal();
        },
            'MigrationFoundation::testPersistentJournalSurvivesFreshConnection' => function () {
            $this->assert_MigrationFoundation_testPersistentJournalSurvivesFreshConnection();
        },
            'MigrationFoundation::testTamperedPersistedManifestFailsFreshVerify' => function () {
            $this->assert_MigrationFoundation_testTamperedPersistedManifestFailsFreshVerify();
        },
            'MigrationFoundation::testRolloutGateDefaultsOffAndRejectsUnknownMode' => function () {
            $this->assert_MigrationFoundation_testRolloutGateDefaultsOffAndRejectsUnknownMode();
        },
            'MigrationFoundation::testRolloutOnRequiresExplicitToken' => function () {
            $this->assert_MigrationFoundation_testRolloutOnRequiresExplicitToken();
        },
            'MigrationFoundation::testManifestHashChangesWhenFieldChanges' => function () {
            $this->assert_MigrationFoundation_testManifestHashChangesWhenFieldChanges();
        },
            'MigrationFoundation::testDestroyRefusesSharedDatabaseName' => function () {
            $this->assert_MigrationFoundation_testDestroyRefusesSharedDatabaseName();
        },
            'MigrationFoundation::testFullCloneStreamsDumpThroughTemporaryFile' => function () {
            $this->assert_MigrationFoundation_testFullCloneStreamsDumpThroughTemporaryFile();
        },
            'MigrationFoundation::testDestroyFailureIsReportedAndKeepsRegistryEntry' => function () {
            $this->assert_MigrationFoundation_testDestroyFailureIsReportedAndKeepsRegistryEntry();
        },
            'MigrationFoundation::testRegistryAllowlistUnlocksGuardedPreflight' => function () {
            $this->assert_MigrationFoundation_testRegistryAllowlistUnlocksGuardedPreflight();
        },
            'ColumnRestoreLaneSelect::testSelectsNewestUpgradeDropColumnOverOlderRollbackLane' => function () {
            $this->assert_ColumnRestoreLaneSelect_testSelectsNewestUpgradeDropColumnOverOlderRollbackLane();
        },
            'ColumnRestoreLaneSelect::testSelectsRollbackLaneWhenItIsNewerThanUpgradeDrop' => function () {
            $this->assert_ColumnRestoreLaneSelect_testSelectsRollbackLaneWhenItIsNewerThanUpgradeDrop();
        },
            'ColumnRestoreLaneSelect::testIgnoresMismatchedColumnName' => function () {
            $this->assert_ColumnRestoreLaneSelect_testIgnoresMismatchedColumnName();
        },
            'ColumnRestoreLaneSelect::testSkipsNewestDropWithoutBackupAndSelectsOlderRollbackLane' => function () {
            $this->assert_ColumnRestoreLaneSelect_testSkipsNewestDropWithoutBackupAndSelectsOlderRollbackLane();
        },
            'ColumnRestoreLaneSelect::testSkipsNewestDropWithoutBackupAndSelectsOlderUpgradeDropLane' => function () {
            $this->assert_ColumnRestoreLaneSelect_testSkipsNewestDropWithoutBackupAndSelectsOlderUpgradeDropLane();
        },
            'ColumnRestoreLaneSelect::testReturnsNullWhenNoCandidateHasQualifiedBackup' => function () {
            $this->assert_ColumnRestoreLaneSelect_testReturnsNullWhenNoCandidateHasQualifiedBackup();
        },
            'IndexDefinition::testAssertDeclaredNamesRejectsCaseInsensitiveCollision' => function () {
            $this->assert_IndexDefinition_testAssertDeclaredNamesRejectsCaseInsensitiveCollision();
        },
            'IndexDefinition::testResolveImplicitNameAvoidsReservedIdentities' => function () {
            $this->assert_IndexDefinition_testResolveImplicitNameAvoidsReservedIdentities();
        },
            'IndexDefinition::testEqualsIgnoresMethodOnSqliteNonUniqueIndexes' => function () {
            $this->assert_IndexDefinition_testEqualsIgnoresMethodOnSqliteNonUniqueIndexes();
        },
            'TimestampColumnOrigin::testDeclarativeTraitLocksCreateFalse' => function () {
            $this->assert_TimestampColumnOrigin_testDeclarativeTraitLocksCreateFalse();
        },
            'TimestampColumnOrigin::testInheritedAbstractModelTimestampsMarkFrameworkBaseNotPollution' => function () {
            $this->assert_TimestampColumnOrigin_testInheritedAbstractModelTimestampsMarkFrameworkBaseNotPollution();
        },
            'TimestampColumnOrigin::testLegacyCreateInjectsTimestampsAndMarksLegacyImplicit' => function () {
            $this->assert_TimestampColumnOrigin_testLegacyCreateInjectsTimestampsAndMarksLegacyImplicit();
        },
            'HasQualifiedColumnBackupTableKey::testRejectsColumnOnlyHitWhenTableCandidatesProvided' => function () {
            $this->assert_HasQualifiedColumnBackupTableKey_testRejectsColumnOnlyHitWhenTableCandidatesProvided();
        },
            'HasQualifiedColumnBackupTableKey::testAcceptsNormalizedTableKeyMatch' => function () {
            $this->assert_HasQualifiedColumnBackupTableKey_testAcceptsNormalizedTableKeyMatch();
        },
            'HasQualifiedColumnBackupTableKey::testEmptyCandidatesStillAllowColumnOnly' => function () {
            $this->assert_HasQualifiedColumnBackupTableKey_testEmptyCandidatesStillAllowColumnOnly();
        },
            'PhysicalBackupAbsentTable::testAbsentPhysicalTableStructureIsPersistedNotRejected' => function () {
            $this->assert_PhysicalBackupAbsentTable_testAbsentPhysicalTableStructureIsPersistedNotRejected();
        },
        ];
    }

private function assert_AbstractModelUpdateMerge_testCheckUpdateOrInsertMergesChangedFieldsWithoutArrayWipe(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Database/AbstractModel.php');
    self::assertStringContainsString('getModelChangedData()', $source);
    self::assertStringContainsString('按字段合并回写', $source);
    self::assertStringContainsString('foreach ($data as $field => $value)', $source);
    self::assertStringContainsString('$this->setData((string)$field, $value)', $source);
    self::assertStringNotContainsString("# 更新数据\n            \$this->setData(\$data);", $source);
}

/** @return list<array{0:class-string<DialectInterface>,1:string}> */
private function dialectProvider_DialectPortableExpressions(): array
{
    return [[MysqlDialect::class, 'mysql'], [PgsqlDialect::class, 'pgsql'], [SqliteDialect::class, 'sqlite']];
}

/**
 * @dataProvider dialectProvider
 * @param class-string<DialectInterface> $class
 */
private function assert_DialectPortableExpressions_testDialectExposesPortableHelpers(string $class, string $driver): void
{
    /** @var DialectInterface $dialect */
    $dialect = new $class();
    self::assertSame($driver, $dialect->getDriverType());
    self::assertNotSame('', $dialect->unixEpochExpression(true));
    self::assertNotSame('', $dialect->unixEpochExpression(false));
    self::assertStringContainsString('created_at', $dialect->timeBucketExpression('created_at', 5));
    self::assertStringContainsString('payload', $dialect->jsonExtractText('payload', 'testId'));
    self::assertStringContainsString('LIMIT', trim($dialect->limitOffset(10, 0)));
}

private function assert_DialectPortableExpressions_testConnectorsPublishSqlDialect(): void
{
    foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
        self::assertTrue(method_exists($connectorClass, 'getSqlDialect'));
    }
    self::assertTrue(method_exists(\Weline\Framework\Database\Connection\Api\ConnectorInterface::class, 'getSqlDialect'));
}

private function assert_DialectPortableExpressions_testBusinessInventoryNoLongerOwnsDialectKeywords(): void
{
    $paths = [BP . 'app/code/Weline/Meta/Service/Repository/MetaConfigRepository.php', BP . 'app/code/Weline/Order/Service/DisplayNumberAllocator.php', BP . 'app/code/Weline/Websites/Service/WebsiteBrandIdentitySeedService.php', BP . 'app/code/Weline/Framework/Event/Async/DeliveryRetryPolicy.php', BP . 'app/code/Weline/Framework/Service/Query/Store/DatabaseFrontendWorkerCredentialTransaction.php', BP . 'app/code/Weline/Visitor/Service/PixelStatisticsService.php', BP . 'app/code/Weline/Visitor/Model/Pixel.php', BP . 'app/code/Weline/DataTable/Setup/InstallData.php'];
    // 业务盘点文件不得再内嵌引擎方言关键字（Dialect 实现文件除外）
    $forbidden = ['ON CONFLICT', 'ON DUPLICATE KEY', 'INSERT IGNORE', 'ENGINE=InnoDB', 'JSON_EXTRACT(', 'DATE_FORMAT(', "strftime('%s'", 'UNIX_TIMESTAMP', 'pgsql:host='];
    foreach ($paths as $path) {
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $source, basename($path) . ' still contains dialect fragment: ' . $needle);
        }
    }
}

private function assert_DeclarativeSchemaConnector_testEverySupportedConnectorPublishesDeclarativeSchemaCreation(): void
{
    self::assertTrue(method_exists(ConnectorInterface::class, 'createTableFromSchema'));
    foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connector) {
        self::assertContains(CreatesTableFromSchemaTrait::class, class_uses($connector), $connector . ' must implement the shared declarative schema contract.');
        self::assertTrue(method_exists($connector, 'createTableFromSchema'));
    }
}

private function assert_DeclarativeSchemaConnector_testUnavailableDriversFailWithoutEnteringTranslationBootstrap(): void
{
    $driver = 'wls_missing_driver_' . bin2hex(random_bytes(4));
    foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
        $connector = new $connectorClass(new ConfigProvider(['type' => $driver, 'database' => ':memory:', 'prefix' => '']));
        try {
            $connector->create();
            self::fail($connectorClass . ' accepted an unavailable PDO driver.');
        } catch (LinkException $exception) {
            self::assertStringContainsString($driver, $exception->getMessage());
            self::assertStringContainsString('Available drivers:', $exception->getMessage());
        }
    }
}

private function assert_DeclarativeSchemaConnector_testUnavailableDriverBranchesAreLocalizationIndependent(): void
{
    foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
        $source = file_get_contents((new \ReflectionClass($connectorClass))->getFileName());
        self::assertIsString($source);
        $branchStart = strpos($source, '$availableDrivers = PDO::getAvailableDrivers();');
        $poolStart = strpos($source, 'ConnectionPool::acquire(', $branchStart ?: 0);
        self::assertIsInt($branchStart);
        self::assertIsInt($poolStart);
        self::assertStringNotContainsString('__(', substr($source, $branchStart, $poolStart - $branchStart));
    }
}

private function assert_MigrationFoundation_testFoundationCliFailureReturnsNonZeroStatus(): void
{
    $command = \Weline\Framework\Manager\ObjectManager::getInstance(Foundation::class);
    self::assertSame(2, $command->execute(['mig:foundation', 'journal-verify']));
}

private function assert_MigrationFoundation_testSharedWelineDatabaseIsHardRejected(): void
{
    $guard = new DatabaseFingerprintGuard();
    try {
    $this->expectExceptionMessage('migration_db_denied');
    self::fail('Expected exception was not thrown');
    } catch (\RuntimeException $__e) {
    self::assertInstanceOf(\RuntimeException::class, $__e);
    }
    $guard->assertIsolatedDatabase(['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'weline', 'username' => 'weline']);
}

private function assert_MigrationFoundation_testCloneDatabaseNameIsAcceptedWhenAllowlisted(): void
{
    $config = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'mig_clone_p1b_20260724', 'username' => 'weline'];
    $guard = new DatabaseFingerprintGuard();
    $fp = $guard->fingerprint($config);
    $guard = new DatabaseFingerprintGuard(allowlistFingerprints: [$fp]);
    self::assertSame($fp, $guard->assertIsolatedDatabase($config));
}

private function assert_MigrationFoundation_testTamperedManifestRejectsApplyWithZeroBusinessWrite(): void
{
    $svc = new MigrationCheckpointService(new DatabaseFingerprintGuard());
    $clone = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'mig_clone_foundation_test', 'username' => 'weline'];
    $fp = (new DatabaseFingerprintGuard())->fingerprint($clone);
    // 无 allowlist 时只要命名合规即可通过 assertIsolated
    $manifest = MigrationManifest::fromArray(['checkpoint_id' => 'cp-1', 'phase' => 'p1a-preflight', 'repo' => 'framework', 'branch' => 'master', 'commit' => 'abc', 'connector_fingerprint' => $fp, 'schema_fingerprints' => ['t' => 'h1'], 'row_counts' => ['t' => 1], 'row_hashes' => ['t' => 'rh1'], 'watermarks' => ['queue' => 0], 'backup_ref' => 'none', 'created_at' => '2026-07-24T00:00:00Z']);
    $hash = $svc->checkpoint($manifest);
    self::assertNotSame('', $hash);
    $tampered = $manifest->withTamper('commit', 'evil');
    try {
    $this->expectExceptionMessage('migration_manifest_tampered');
    self::fail('Expected exception was not thrown');
    } catch (\RuntimeException $__e) {
    self::assertInstanceOf(\RuntimeException::class, $__e);
    }
    $svc->applyGuard($clone, 'cp-1', $tampered);
}

private function assert_MigrationFoundation_testValidManifestApplyGuardAppendsJournal(): void
{
    $svc = new MigrationCheckpointService(new DatabaseFingerprintGuard());
    $clone = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'test_mig_foundation', 'username' => 'weline'];
    $fp = (new DatabaseFingerprintGuard())->fingerprint($clone);
    $manifest = MigrationManifest::fromArray(['checkpoint_id' => 'cp-2', 'phase' => 'p1a-apply', 'repo' => 'framework', 'branch' => 'master', 'commit' => 'def', 'connector_fingerprint' => $fp, 'schema_fingerprints' => [], 'row_counts' => [], 'row_hashes' => [], 'watermarks' => [], 'backup_ref' => 'bak-1', 'created_at' => '2026-07-24T01:00:00Z']);
    $svc->checkpoint($manifest);
    $svc->applyGuard($clone, 'cp-2', $manifest);
    $journal = $svc->journal('cp-2');
    self::assertGreaterThanOrEqual(2, \count($journal));
    self::assertSame('apply_guard_passed', $journal[\count($journal) - 1]['event']);
}

private function assert_MigrationFoundation_testPersistentJournalSurvivesFreshConnection(): void
{
    $dir = \sys_get_temp_dir() . '/mig_journal_' . \uniqid('', true);
    $store = new MigrationCheckpointJournalStore($dir);
    $guard = new DatabaseFingerprintGuard();
    $svc = new MigrationCheckpointService($guard, $store);
    $clone = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'mig_clone_journal_unit', 'username' => 'weline'];
    $fp = $guard->fingerprint($clone);
    $manifest = MigrationManifest::fromArray(['checkpoint_id' => 'cp-persist-1', 'phase' => 'foundation-journal', 'repo' => 'framework', 'branch' => 'master', 'commit' => 'persist', 'connector_fingerprint' => $fp, 'schema_fingerprints' => ['t' => 'h'], 'row_counts' => ['t' => 2], 'row_hashes' => ['t' => 'rh'], 'watermarks' => [], 'backup_ref' => 'bak', 'created_at' => '2026-07-24T02:00:00Z']);
    $hash = $svc->checkpoint($manifest);
    $svc->applyGuard($clone, 'cp-persist-1', $manifest);
    $fresh = new MigrationCheckpointService($guard, new MigrationCheckpointJournalStore($dir));
    self::assertTrue($fresh->hasCheckpoint('cp-persist-1'));
    self::assertSame($hash, $fresh->manifestHash('cp-persist-1'));
    $fresh->assertManifestUntampered('cp-persist-1', $manifest);
    $journal = $fresh->journal('cp-persist-1');
    self::assertGreaterThanOrEqual(2, \count($journal));
    $verify = $svc->verifyFresh('cp-persist-1');
    self::assertTrue($verify['ok']);
    self::assertSame($hash, $verify['manifest_hash']);
    self::assertSame('apply_guard_passed', $verify['last_event']);
    foreach (\glob($dir . '/*') ?: [] as $f) {
        @\unlink($f);
    }
    @\rmdir($dir);
}

private function assert_MigrationFoundation_testTamperedPersistedManifestFailsFreshVerify(): void
{
    $dir = \sys_get_temp_dir() . '/mig_journal_' . \uniqid('', true);
    $store = new MigrationCheckpointJournalStore($dir);
    $guard = new DatabaseFingerprintGuard();
    $svc = new MigrationCheckpointService($guard, $store);
    $manifest = MigrationManifest::fromArray(['checkpoint_id' => 'cp-tamper-1', 'phase' => 'x', 'repo' => 'r', 'branch' => 'b', 'commit' => 'c1', 'connector_fingerprint' => 'fp', 'schema_fingerprints' => [], 'row_counts' => [], 'row_hashes' => [], 'watermarks' => [], 'backup_ref' => '', 'created_at' => '2026-07-24T00:00:00Z']);
    $svc->checkpoint($manifest);
    $path = $dir . '/cp-tamper-1.json';
    $raw = \file_get_contents($path);
    self::assertNotFalse($raw);
    $data = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
    $data['manifest']['commit'] = 'evil';
    \file_put_contents($path, \json_encode($data, \JSON_THROW_ON_ERROR));
    $verify = $svc->verifyFresh('cp-tamper-1');
    self::assertFalse($verify['ok']);
    self::assertSame('migration_manifest_tampered', $verify['error']);
    foreach (\glob($dir . '/*') ?: [] as $f) {
        @\unlink($f);
    }
    @\rmdir($dir);
}

private function assert_MigrationFoundation_testRolloutGateDefaultsOffAndRejectsUnknownMode(): void
{
    $gate = new CommerceRolloutGate();
    self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $gate->mode('queue_envelope'));
    try {
    $gate->assertMutable('queue_envelope');
    self::fail('Expected exception was not thrown');
    } catch (\RuntimeException $__e) {
    self::assertInstanceOf(\RuntimeException::class, $__e);
    }
}

private function assert_MigrationFoundation_testRolloutOnRequiresExplicitToken(): void
{
    $gate = new CommerceRolloutGate();
    try {
    $gate->setMode('payment', CommerceRolloutGateInterface::MODE_ON);
    self::fail('Expected exception was not thrown');
    } catch (\InvalidArgumentException $__e) {
    self::assertInstanceOf(\InvalidArgumentException::class, $__e);
    }
}

private function assert_MigrationFoundation_testManifestHashChangesWhenFieldChanges(): void
{
    $base = MigrationManifest::fromArray(['checkpoint_id' => 'cp-h', 'phase' => 'x', 'repo' => 'r', 'branch' => 'b', 'commit' => 'c1', 'connector_fingerprint' => 'fp', 'schema_fingerprints' => [], 'row_counts' => [], 'row_hashes' => [], 'watermarks' => [], 'backup_ref' => '', 'created_at' => '2026-07-24T00:00:00Z']);
    $other = $base->withTamper('commit', 'c2');
    self::assertNotSame($base->hash(), $other->hash());
}

private function assert_MigrationFoundation_testDestroyRefusesSharedDatabaseName(): void
{
    $svc = new MigrationCloneService(new MigrationCloneRegistry(\sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid()), new DatabaseFingerprintGuard());
    try {
    $this->expectExceptionMessage('migration_clone_destroy_refused');
    self::fail('Expected exception was not thrown');
    } catch (\RuntimeException $__e) {
    self::assertInstanceOf(\RuntimeException::class, $__e);
    }
    $svc->destroy('weline');
}

private function assert_MigrationFoundation_testFullCloneStreamsDumpThroughTemporaryFile(): void
{
    $root = \sys_get_temp_dir() . '/mig_clone_stream_' . \uniqid('', true);
    $bin = $root . '/bin';
    $registryDir = $root . '/registry';
    $restoreMarker = $root . '/restore.marker';
    self::assertTrue(\mkdir($bin, 0777, true));
    $scripts = ['createdb' => <<<'SH'
    #!/bin/sh
    exit 0
    SH, 'pg_dump' => <<<'SH'
    #!/bin/sh
    dump_file=''
    while [ "$#" -gt 0 ]; do
        if [ "$1" = '--file' ] && [ "$#" -gt 1 ]; then
            shift
            dump_file="$1"
        fi
        shift
    done
    if [ -z "$dump_file" ]; then
        echo 'stream_file_required' >&2
        exit 41
    fi
    printf 'streamed-dump' > "$dump_file"
    SH, 'psql' => <<<'SH'
    #!/bin/sh
    dump_file=''
    while [ "$#" -gt 0 ]; do
        if [ "$1" = '-f' ] && [ "$#" -gt 1 ]; then
            shift
            dump_file="$1"
        fi
        shift
    done
    if [ ! -f "$dump_file" ]; then
        echo 'restore_file_missing' >&2
        exit 42
    fi
    cp "$dump_file" "$MIG_TEST_RESTORE_MARKER"
    SH, 'dropdb' => <<<'SH'
    #!/bin/sh
    exit 0
    SH];
    foreach ($scripts as $name => $script) {
        $path = $bin . '/' . $name;
        self::assertNotFalse(\file_put_contents($path, $script));
        self::assertTrue(\chmod($path, 0755));
    }
    $previousEnvPath = $_ENV['PATH'] ?? null;
    $previousServerPath = $_SERVER['PATH'] ?? null;
    $previousProcessPath = \getenv('PATH');
    $previousMarker = $_ENV['MIG_TEST_RESTORE_MARKER'] ?? null;
    $previousServerMarker = $_SERVER['MIG_TEST_RESTORE_MARKER'] ?? null;
    $_ENV['PATH'] = $bin . ':' . (string) ($previousEnvPath ?? \getenv('PATH'));
    $_SERVER['PATH'] = $_ENV['PATH'];
    \putenv('PATH=' . $_ENV['PATH']);
    $_ENV['MIG_TEST_RESTORE_MARKER'] = $restoreMarker;
    $_SERVER['MIG_TEST_RESTORE_MARKER'] = $restoreMarker;
    $registry = new MigrationCloneRegistry($registryDir);
    try {
        $handle = (new MigrationCloneService($registry, new DatabaseFingerprintGuard()))->create(['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'weline', 'username' => 'weline'], MigrationCloneService::MODE_FULL, 'streamtest', 'phpunit');
        self::assertSame('streamed-dump', \file_get_contents($restoreMarker));
        self::assertSame(MigrationCloneService::MODE_FULL, $handle->mode);
    } finally {
        if ($previousEnvPath === null) {
            unset($_ENV['PATH']);
        } else {
            $_ENV['PATH'] = $previousEnvPath;
        }
        if ($previousServerPath === null) {
            unset($_SERVER['PATH']);
        } else {
            $_SERVER['PATH'] = $previousServerPath;
        }
        $previousProcessPath === false ? \putenv('PATH') : \putenv('PATH=' . $previousProcessPath);
        if ($previousMarker === null) {
            unset($_ENV['MIG_TEST_RESTORE_MARKER']);
        } else {
            $_ENV['MIG_TEST_RESTORE_MARKER'] = $previousMarker;
        }
        if ($previousServerMarker === null) {
            unset($_SERVER['MIG_TEST_RESTORE_MARKER']);
        } else {
            $_SERVER['MIG_TEST_RESTORE_MARKER'] = $previousServerMarker;
        }
        foreach (\glob($registryDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        foreach (\glob($bin . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\unlink($restoreMarker);
        @\rmdir($registryDir);
        @\rmdir($bin);
        @\rmdir($root);
    }
}

private function assert_MigrationFoundation_testDestroyFailureIsReportedAndKeepsRegistryEntry(): void
{
    $dir = \sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid('', true);
    $registry = new MigrationCloneRegistry($dir);
    $database = 'test_mig_destroy_failure_' . \bin2hex(\random_bytes(2));
    $config = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '1', 'database' => $database, 'username' => 'weline'];
    $registry->register(new MigrationCloneHandle(cloneId: 'mig_destroy_failure', database: $database, fingerprint: (new DatabaseFingerprintGuard())->fingerprint($config), mode: 'schema', sourceDatabase: 'weline', createdAt: \gmdate('c'), owner: 'phpunit', config: $config, createCommand: 'createdb', destroyCommand: 'dropdb'));
    $svc = new MigrationCloneService($registry, new DatabaseFingerprintGuard());
    try {
        $svc->destroy($database);
        self::fail('Destroying through an unreachable PostgreSQL endpoint must fail.');
    } catch (\RuntimeException $e) {
        self::assertStringContainsString('migration_clone_destroy_failed:' . $database, $e->getMessage());
        self::assertNotNull($registry->get($database));
    } finally {
        $registry->forget($database);
        @\rmdir($dir);
    }
}

private function assert_MigrationFoundation_testRegistryAllowlistUnlocksGuardedPreflight(): void
{
    $dir = \sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid('', true);
    $registry = new MigrationCloneRegistry($dir);
    $config = ['type' => 'pgsql', 'hostname' => '127.0.0.1', 'hostport' => '5432', 'database' => 'mig_clone_unit_' . \bin2hex(\random_bytes(2)), 'username' => 'weline'];
    $fp = (new DatabaseFingerprintGuard())->fingerprint($config);
    $registry->register(new MigrationCloneHandle(cloneId: 'mig_unit', database: $config['database'], fingerprint: $fp, mode: 'schema', sourceDatabase: 'weline', createdAt: \gmdate('c'), owner: 'phpunit', config: $config, createCommand: 'createdb', destroyCommand: 'dropdb'));
    $svc = new MigrationCloneService($registry, new DatabaseFingerprintGuard());
    $preflight = (new MigrationCheckpointService($svc->guardedFingerprint()))->preflight($config);
    self::assertTrue($preflight['ok']);
    self::assertSame($fp, $preflight['fingerprint']);
    $registry->forget($config['database']);
    @\rmdir($dir);
}

private function assert_ColumnRestoreLaneSelect_testSelectsNewestUpgradeDropColumnOverOlderRollbackLane(): void
{
    // Quoted/qualified vs bare format-2 both normalize to public.w_demo_table
    // when runtimeQualifiers are empty (connector without formatTableName probe).
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_ROLLED_BACK => [$this->item_ColumnRestoreLaneSelect(100, SchemaDiffOp::KIND_ADD_COLUMN, 'public.w_demo_table', 'sample_payload')], Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(200, SchemaDiffOp::KIND_DROP_COLUMN, '"public"."w_demo_table"', 'sample_payload')]]);
    $executor = new SchemaMigrationExecutor($this->createMock(EventsManager::class), $migration, $this->backupServiceProving_ColumnRestoreLaneSelect([200 => MigrationBackup::SCOPE_UPGRADE, 100 => MigrationBackup::SCOPE_ROLLBACK]));
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', '"public"."w_demo_table"', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertSame(200, $selected['migration_id'] ?? null);
    self::assertSame(MigrationBackup::SCOPE_UPGRADE, $selected['backup_scope'] ?? null);
}

private function assert_ColumnRestoreLaneSelect_testSelectsRollbackLaneWhenItIsNewerThanUpgradeDrop(): void
{
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_ROLLED_BACK => [$this->item_ColumnRestoreLaneSelect(300, SchemaDiffOp::KIND_ADD_COLUMN, 'w_demo_table', 'sample_payload')], Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(200, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload')]]);
    $executor = new SchemaMigrationExecutor($this->createMock(EventsManager::class), $migration, $this->backupServiceProving_ColumnRestoreLaneSelect([300 => MigrationBackup::SCOPE_ROLLBACK, 200 => MigrationBackup::SCOPE_UPGRADE]));
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', 'w_demo_table', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertSame(300, $selected['migration_id'] ?? null);
    self::assertSame(MigrationBackup::SCOPE_ROLLBACK, $selected['backup_scope'] ?? null);
}

private function assert_ColumnRestoreLaneSelect_testIgnoresMismatchedColumnName(): void
{
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(200, SchemaDiffOp::KIND_DROP_COLUMN, 'demo_table', 'other_col')]]);
    $executor = new SchemaMigrationExecutor($this->createMock(EventsManager::class), $migration, $this->backupServiceProving_ColumnRestoreLaneSelect([200 => MigrationBackup::SCOPE_UPGRADE]));
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', 'demo_table', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertNull($selected);
}

/**
 * Escalate C1 / option C: newest installed DROP lacks TYPE_COLUMN+SCOPE_UPGRADE backup;
 * older R1 with SCOPE_ROLLBACK backup must win — must not silent-pick 400 then restored:0.
 */
private function assert_ColumnRestoreLaneSelect_testSkipsNewestDropWithoutBackupAndSelectsOlderRollbackLane(): void
{
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_ROLLED_BACK => [$this->item_ColumnRestoreLaneSelect(100, SchemaDiffOp::KIND_ADD_COLUMN, 'w_demo_table', 'sample_payload')], Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload')]]);
    $executor = new SchemaMigrationExecutor(
        $this->createMock(EventsManager::class),
        $migration,
        // Only R1 migration 100 has a qualified backup; newest DROP 400 does not.
        $this->backupServiceProving_ColumnRestoreLaneSelect([100 => MigrationBackup::SCOPE_ROLLBACK])
    );
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', 'w_demo_table', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertSame(100, $selected['migration_id'] ?? null);
    self::assertSame(MigrationBackup::SCOPE_ROLLBACK, $selected['backup_scope'] ?? null);
}

/**
 * Multi-generation R2: newest DROP without backup must not block an older DROP that has
 * TYPE_COLUMN+SCOPE_UPGRADE backup.
 */
private function assert_ColumnRestoreLaneSelect_testSkipsNewestDropWithoutBackupAndSelectsOlderUpgradeDropLane(): void
{
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(150, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'), $this->item_ColumnRestoreLaneSelect(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload')]]);
    $executor = new SchemaMigrationExecutor($this->createMock(EventsManager::class), $migration, $this->backupServiceProving_ColumnRestoreLaneSelect([150 => MigrationBackup::SCOPE_UPGRADE]));
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', 'w_demo_table', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertSame(150, $selected['migration_id'] ?? null);
    self::assertSame(MigrationBackup::SCOPE_UPGRADE, $selected['backup_scope'] ?? null);
}

private function assert_ColumnRestoreLaneSelect_testReturnsNullWhenNoCandidateHasQualifiedBackup(): void
{
    $migration = $this->migrationReturning_ColumnRestoreLaneSelect([Migration::STATUS_INSTALLED => [$this->item_ColumnRestoreLaneSelect(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload')]]);
    $executor = new SchemaMigrationExecutor($this->createMock(EventsManager::class), $migration, $this->backupServiceProving_ColumnRestoreLaneSelect([]));
    $selected = $this->invokeSelect_ColumnRestoreLaneSelect($executor, 'Weline_Demo', 'w_demo_table', 'sample_payload', 999, $this->createMock(ConnectorInterface::class));
    self::assertNull($selected);
}

/**
 * @param array<int, string> $qualifiedByMigrationId migration_id => expected backup_scope
 */
private function backupServiceProving_ColumnRestoreLaneSelect(array $qualifiedByMigrationId): BackupService
{
    $backup = $this->createMock(BackupService::class);
    $backup->method('hasQualifiedColumnBackup')->willReturnCallback(static function (int $migrationId, string $columnName, string $backupScope, array $tableNameCandidates = []) use ($qualifiedByMigrationId): bool {
        unset($columnName, $tableNameCandidates);
        return ($qualifiedByMigrationId[$migrationId] ?? null) === $backupScope;
    });
    return $backup;
}

/**
 * @param array<string, list<object>> $byStatus
 */
private function migrationReturning_ColumnRestoreLaneSelect(array $byStatus): Migration
{
    return new class($byStatus) extends Migration
    {
        private ?string $statusFilter = null;
        /** @param array<string, list<object>> $byStatus */
        public function __construct(private readonly array $byStatus)
        {
            // Skip Model construction / DI.
        }
        public function reset(): static
        {
            $this->statusFilter = null;
            return $this;
        }
        public function where(mixed $field, mixed $value = true, string $condition = '=', string $op = 'AND'): static
        {
            if ($field === Migration::schema_fields_STATUS) {
                $this->statusFilter = (string) $value;
            }
            return $this;
        }
        public function order(string $field, string $direction = 'ASC'): static
        {
            return $this;
        }
        public function select(string $fields = '*'): static
        {
            return $this;
        }
        public function fetch(string $model_class = ''): static
        {
            return $this;
        }
        /** @return list<object> */
        public function getItems(): array
        {
            return $this->byStatus[$this->statusFilter ?? ''] ?? [];
        }
    };
}

private function item_ColumnRestoreLaneSelect(int $id, string $kind, string $table, string $column): object
{
    return new class($id, $kind, $table, $column)
    {
        public function __construct(private readonly int $id, private readonly string $kind, private readonly string $table, private readonly string $column)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getData(string $key): mixed
        {
            return match ($key) {
                Migration::schema_fields_OPERATION_KIND => $this->kind,
                Migration::schema_fields_SCHEMA_TABLE_NAME => $this->table,
                Migration::schema_fields_OPERATION_PAYLOAD => json_encode(['name' => $this->column], JSON_UNESCAPED_UNICODE),
                default => null,
            };
        }
    };
}

/** @return array{migration_id: int, backup_scope: string}|null */
private function invokeSelect_ColumnRestoreLaneSelect(SchemaMigrationExecutor $executor, string $module, string $table, string $column, int $currentId, ConnectorInterface $connector): ?array
{
    $method = new \ReflectionMethod(SchemaMigrationExecutor::class, 'selectEligibleColumnRestoreCandidate');
    $method->setAccessible(true);
    /** @var array{migration_id: int, backup_scope: string}|null $result */
    $result = $method->invoke($executor, $module, $table, $column, $currentId, $connector);
    return $result;
}

private function assert_IndexDefinition_testAssertDeclaredNamesRejectsCaseInsensitiveCollision(): void
{
    try {
    IndexDefinitionContract::assertDeclaredNames([new IndexDefinition(name: 'idx_foo', columns: ['a']), new IndexDefinition(name: 'IDX_FOO', columns: ['b'])]);
    self::fail('Expected exception was not thrown');
    } catch (\InvalidArgumentException $__e) {
    self::assertInstanceOf(\InvalidArgumentException::class, $__e);
    }
}

private function assert_IndexDefinition_testResolveImplicitNameAvoidsReservedIdentities(): void
{
    $reserved = ['uk_email' => true];
    $name = IndexDefinitionContract::resolveImplicitName('demo', 'email', $reserved);
    self::assertNotSame('uk_email', $name);
    self::assertStringStartsWith('uk_email_', $name);
}

private function assert_IndexDefinition_testEqualsIgnoresMethodOnSqliteNonUniqueIndexes(): void
{
    $declared = new IndexDefinition(name: 'idx_a', columns: ['a'], type: 'INDEX', method: 'BTREE');
    $actual = new IndexDefinition(name: 'idx_a', columns: ['a'], type: 'DEFAULT', method: 'HASH');
    self::assertTrue(IndexDefinitionContract::equals($declared, $actual, 'sqlite'));
    self::assertFalse(IndexDefinitionContract::equals($declared, $actual, 'mysql'));
}

/** C-RC-1: declarative createTableFromSchema must call create(false). */
private function assert_TimestampColumnOrigin_testDeclarativeTraitLocksCreateFalse(): void
{
    $path = (new \ReflectionClass(CreatesTableFromSchemaTrait::class))->getFileName();
    self::assertIsString($path);
    $source = file_get_contents($path);
    self::assertIsString($source);
    self::assertStringContainsString('create(false)', $source, 'C-RC-1: CreatesTableFromSchemaTrait must pass create(false).');
    self::assertMatchesRegularExpression('/\$create->create\s*\(\s*false\s*\)/', $source, 'C-RC-1: create(false) must be invoked on the Create builder.');
}

/** C-RC-2: AbstractModel inherited timestamps → framework_base_timestamps, not pollution. */
private function assert_TimestampColumnOrigin_testInheritedAbstractModelTimestampsMarkFrameworkBaseNotPollution(): void
{
    $auditor = new TimestampColumnOriginAuditor();
    $model = FrameworkBaseTimestampFixtureModel::class;
    self::assertSame(TimestampColumnOriginMarkers::FRAMEWORK_BASE, $auditor->classifyInheritedTimestamp($model, TimestampColumnOriginMarkers::CREATE_TIME));
    self::assertSame(TimestampColumnOriginMarkers::FRAMEWORK_BASE, $auditor->classifyInheritedTimestamp($model, TimestampColumnOriginMarkers::UPDATE_TIME));
    $audit = $auditor->auditModel($model);
    self::assertCount(2, $audit);
    foreach ($audit as $row) {
        self::assertSame(TimestampColumnOriginMarkers::FRAMEWORK_BASE, $row['marker']);
        self::assertFalse($auditor->isUndeclaredPollution($row['marker']), 'C-RC-2: framework_base_timestamps must not be undeclared pollution.');
        self::assertNotSame(TimestampColumnOriginMarkers::UNDECLARED_POLLUTION, $row['marker']);
    }
    self::assertSame(TimestampColumnOriginMarkers::FRAMEWORK_BASE, 'framework_base_timestamps');
}

/** C-RC-3: legacy create()/create(true) still injects; marker = legacy_implicit_timestamps. */
private function assert_TimestampColumnOrigin_testLegacyCreateInjectsTimestampsAndMarksLegacyImplicit(): void
{
    $auditor = new TimestampColumnOriginAuditor();
    self::assertSame(TimestampColumnOriginMarkers::LEGACY_IMPLICIT, $auditor->markerForCreateCall(true));
    self::assertNull($auditor->markerForCreateCall(false));
    self::assertSame(TimestampColumnOriginMarkers::LEGACY_IMPLICIT, 'legacy_implicit_timestamps');
    self::assertFalse($auditor->isUndeclaredPollution(TimestampColumnOriginMarkers::LEGACY_IMPLICIT));
    if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        self::markTestSkipped('pdo_sqlite is not available.');
    }
    $dbPath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline_rc_c3_' . \uniqid('', true) . '.sqlite';
    $connector = new Connector(new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $dbPath, 'persistent' => false]));
    try {
        $legacyTableName = 'w_rc_c3_legacy_default';
        $legacyCreate = $connector->createTable();
        $legacyCreate->createTable($legacyTableName);
        $legacyCreate->addColumn('id', 'int', null, 'PRIMARY KEY AUTO_INCREMENT', '');
        $legacyCreate->addAdditional('');
        $legacyCreate->create();
        $reader = new DbSchemaReader();
        $legacyTable = $reader->readTable($connector, $legacyTableName);
        $legacyColumns = \array_map(static fn(ColumnDefinition $column): string => $column->name, $legacyTable?->columns ?? []);
        self::assertContains('create_time', $legacyColumns);
        self::assertContains('update_time', $legacyColumns);
        // Parallel to ShardSchemaProvisionerTest: legacy path keeps injection + dual-track marker.
        self::assertSame(TimestampColumnOriginMarkers::LEGACY_IMPLICIT, $auditor->markerForCreateCall(true), 'C-RC-3: legacy create() injection must be tagged legacy_implicit_timestamps.');
    } finally {
        $connector->close();
        if (\is_file($dbPath)) {
            \unlink($dbPath);
        }
    }
}

private function assert_HasQualifiedColumnBackupTableKey_testRejectsColumnOnlyHitWhenTableCandidatesProvided(): void
{
    $service = $this->serviceWithBackupRow_HasQualifiedColumnBackupTableKey(migrationId: 9001, tableName: 'other_schema.w_foreign_table', columnName: 'sample_payload', scope: MigrationBackup::SCOPE_UPGRADE);
    self::assertFalse($service->hasQualifiedColumnBackup(9001, 'sample_payload', MigrationBackup::SCOPE_UPGRADE, ['w_demo_table', 'public.w_demo_table']));
}

private function assert_HasQualifiedColumnBackupTableKey_testAcceptsNormalizedTableKeyMatch(): void
{
    $service = $this->serviceWithBackupRow_HasQualifiedColumnBackupTableKey(migrationId: 9002, tableName: '"public"."w_demo_table"', columnName: 'sample_payload', scope: MigrationBackup::SCOPE_UPGRADE);
    self::assertTrue($service->hasQualifiedColumnBackup(9002, 'sample_payload', MigrationBackup::SCOPE_UPGRADE, ['w_demo_table']));
}

private function assert_HasQualifiedColumnBackupTableKey_testEmptyCandidatesStillAllowColumnOnly(): void
{
    $service = $this->serviceWithBackupRow_HasQualifiedColumnBackupTableKey(migrationId: 9003, tableName: 'any_table', columnName: 'sample_payload', scope: MigrationBackup::SCOPE_ROLLBACK);
    self::assertTrue($service->hasQualifiedColumnBackup(9003, 'sample_payload', MigrationBackup::SCOPE_ROLLBACK, []));
}

private function serviceWithBackupRow_HasQualifiedColumnBackupTableKey(int $migrationId, string $tableName, string $columnName, string $scope): BackupService
{
    $row = new class($migrationId, $tableName, $columnName, $scope) extends MigrationBackup
    {
        public function __construct(private readonly int $migrationId, private readonly string $tableName, private readonly string $columnName, private readonly string $scope)
        {
        }
        public function getData(string $key = '', $index = null): mixed
        {
            unset($index);
            return match ($key) {
                MigrationBackup::schema_fields_MIGRATION_ID => $this->migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $this->tableName,
                MigrationBackup::schema_fields_COLUMN_NAME => $this->columnName,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->scope,
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_COLUMN,
                MigrationBackup::schema_fields_BACKUP_DATA => '[]',
                default => null,
            };
        }
    };
    $model = new class($row) extends MigrationBackup
    {
        /** @param object $row */
        public function __construct(private readonly object $row)
        {
        }
        public function reset(): static
        {
            return $this;
        }
        public function where(mixed $field, mixed $value = true, string $condition = '=', string $op = 'AND'): static
        {
            return $this;
        }
        public function order(string $field, string $direction = 'ASC'): static
        {
            return $this;
        }
        public function select(string $fields = '*'): static
        {
            return $this;
        }
        public function fetch(string $model_class = ''): static
        {
            return $this;
        }
        /** @return list<object> */
        public function getItems(): array
        {
            return [$this->row];
        }
    };
    return new BackupService($this->createMock(ConnectionFactory::class), $model, $this->createMock(Printing::class));
}

private function assert_PhysicalBackupAbsentTable_testAbsentPhysicalTableStructureIsPersistedNotRejected(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Database/Service/BackupService.php');
    self::assertStringContainsString("\$result['strategy'] = 'absent'", $src);
    self::assertStringContainsString('weline.absent.table_snapshot.v1', $src);
    self::assertStringContainsString("\$payload['existed'] !== true", $src);
    self::assertStringContainsString('physicalTableExists($identity)', $src);
    self::assertDoesNotMatchRegularExpression('/capturePhysicalTableSnapshot\(\$identity\)\s*;\s*' . '.*?empty\(\$payload\[\'existed\'\]\).*?return false;/s', $src);
}
}
