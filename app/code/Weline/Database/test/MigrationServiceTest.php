<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\BackupService;
use Weline\Database\Model\Migration;
use Weline\Database\Model\MigrationBackup;
use Weline\Database\Interface\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;

/**
 * 数据库迁移服务测试
 */
class MigrationServiceTest extends TestCore
{
    private MigrationService $migrationService;
    private Migration $migrationModel;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->migrationService = ObjectManager::getInstance(MigrationService::class);
        $this->migrationModel = ObjectManager::getInstance(Migration::class);
    }
    
    public function tearDown(): void
    {
        $this->removeFixtureMigration();
        // 清理测试数据 - 删除测试模块的迁移记录
        $testModules = ['Weline_Test', 'Weline_TestModule'];
        foreach ($testModules as $moduleName) {
            $records = (clone $this->migrationModel)->reset()
                ->where(Migration::schema_fields_MODULE, $moduleName)
                ->select()
                ->fetch()
                ->getItems();
            $backupService = ObjectManager::getInstance(BackupService::class);
            foreach ($records as $record) {
                $backupService->cleanupBackupData((int)$record->getId());
            }
            $this->migrationModel->reset()
                ->where(Migration::schema_fields_MODULE, $moduleName)
                ->delete()
                ->fetch();
        }
        $rollbackTable = trim((string)getenv('WELINE_TEST_ROLLBACK_TABLE'));
        if ($rollbackTable !== '') {
            ObjectManager::getInstance(ConnectionFactory::class)
                ->getConnector()
                ->dropTableIfExists($rollbackTable);
        }
        putenv('WELINE_TEST_ROLLBACK_TABLE');
        parent::tearDown();
    }
    
    /**
     * 测试服务初始化
     */
    public function testServiceInitialization()
    {
        $this->assertInstanceOf(MigrationService::class, $this->migrationService);
    }
    
    /**
     * 测试获取模块迁移文件
     */
    public function testGetModuleMigrations()
    {
        $moduleName = 'Weline_Test';
        
        // 创建测试迁移目录
        $migrationPath = "app/code/Weline/Test/Setup/Db/Migration/";
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }
        
        // 创建测试迁移文件
        $testMigrationFile = $migrationPath . 'create_table__test_20250101-v1.0.0.php';
        $testMigrationContent = '<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Database\AbstractMigration;

class CreateTableTest20250101V100 extends AbstractMigration
{
    public function install(): bool { return true; }
    public function uninstall(): bool { return true; }
    public function getInfo(): array { return []; }
    public function validate(): bool { return true; }
    public function getDependencies(): array { return []; }
    public function getDescription(): string { return "创建测试表"; }
    public function getVersion(): string { return "1.0.0"; }
    public function getDate(): string { return "20250101"; }
}';
        
        file_put_contents($testMigrationFile, $testMigrationContent);
        
        // 测试获取迁移文件
        $migrations = $this->migrationService->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        
        // 清理测试文件
        unlink($testMigrationFile);
        rmdir($migrationPath);
    }
    
    /**
     * 测试获取待执行的迁移
     */
    public function testGetPendingMigrations()
    {
        $moduleName = 'Weline_Test';
        
        // 创建测试迁移目录
        $migrationPath = "app/code/Weline/Test/Setup/Db/Migration/";
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }
        
        // 创建测试迁移文件
        $testMigrationFile = $migrationPath . 'create_table__test_20250101-v1.0.0.php';
        $testMigrationContent = '<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Database\AbstractMigration;

class CreateTableTest20250101V100 extends AbstractMigration
{
    public function install(): bool { return true; }
    public function uninstall(): bool { return true; }
    public function getInfo(): array { return []; }
    public function validate(): bool { return true; }
    public function getDependencies(): array { return []; }
    public function getDescription(): string { return "创建测试表"; }
    public function getVersion(): string { return "1.0.0"; }
    public function getDate(): string { return "20250101"; }
}';
        
        file_put_contents($testMigrationFile, $testMigrationContent);
        
        // 测试获取待执行的迁移
        $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
        $this->assertIsArray($pendingMigrations);
        
        // 清理测试文件
        unlink($testMigrationFile);
        rmdir($migrationPath);
    }
    
    /**
     * 测试迁移文件不存在的情况
     */
    public function testGetModuleMigrationsWithNonExistentModule()
    {
        $moduleName = 'NonExistent_Module';
        
        $migrations = $this->migrationService->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations, '不存在的模块应该返回空数组');
    }
    
    /**
     * 测试迁移路径解析
     */
    public function testMigrationPathParsing()
    {
        // 测试标准模块名称解析
        $moduleName = 'Weline_Test';
        $expectedPath = BP . 'app/code/Weline/Test/Setup/Db/Migration/';
        
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('getMigrationPath');
        $method->setAccessible(true);
        
        $actualPath = $method->invoke($this->migrationService, $moduleName);
        $this->assertEquals($expectedPath, $actualPath);
    }
    
    /**
     * 测试迁移类名生成
     */
    public function testMigrationClassNameGeneration()
    {
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('getMigrationClassName');
        $method->setAccessible(true);
        
        // 测试文件名到类名的转换
        $filename = 'create_table__test_20250101-v1.0.0.php';
        $expectedClassName = 'CreateTableTest20250101V100';
        
        $actualClassName = $method->invoke($this->migrationService, $filename);
        $this->assertEquals($expectedClassName, $actualClassName);
    }
    
    /**
     * 测试依赖检查
     */
    public function testDependencyCheck()
    {
        $moduleName = 'Weline_Test';
        $dependencies = ['dependency1.php', 'dependency2.php'];
        
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('checkDependencies');
        $method->setAccessible(true);
        
        // 测试空依赖
        $result = $method->invoke($this->migrationService, $moduleName, []);
        $this->assertTrue($result, '空依赖应该返回true');
        
        // 测试有依赖但未安装的情况
        $result = $method->invoke($this->migrationService, $moduleName, $dependencies);
        $this->assertFalse($result, '未安装的依赖应该返回false');
    }

    public function testRollbackDependencyGraphOrdersDependentBeforeDependency(): void
    {
        $method = (new \ReflectionClass($this->migrationService))
            ->getMethod('sortRollbackDependencyGraph');
        $result = $method->invoke($this->migrationService, [
            ['filename' => 'z_base.php', 'version' => '1.1.0'],
            ['filename' => 'a_child.php', 'version' => '1.1.0'],
        ], [
            'z_base.php' => [],
            'a_child.php' => ['z_base.php'],
        ]);

        $this->assertSame([], $result['blockers']);
        $this->assertSame(
            ['a_child.php', 'z_base.php'],
            array_column($result['migrations'], 'filename')
        );
    }

    public function testRollbackDependencyGraphBlocksOutsideDependentMissingDependencyAndCycle(): void
    {
        $method = (new \ReflectionClass($this->migrationService))
            ->getMethod('sortRollbackDependencyGraph');
        $result = $method->invoke($this->migrationService, [
            ['filename' => 'base.php', 'version' => '1.1.0'],
            ['filename' => 'cycle.php', 'version' => '1.1.0'],
            ['filename' => 'missing.php', 'version' => '1.1.0'],
        ], [
            'base.php' => ['cycle.php'],
            'cycle.php' => ['base.php'],
            'missing.php' => ['not-installed.php'],
            'outside.php' => ['base.php'],
        ]);

        $messages = implode("\n", $result['blockers']);
        $this->assertStringContainsString('outside.php', $messages);
        $this->assertStringContainsString('not-installed.php', $messages);
        $this->assertStringContainsString('循环', $messages);
    }

    public function testScriptColumnRollbackBacksUpAndRestoresValueOnReupgrade(): void
    {
        $table = 'migration_rollback_' . substr(hash('sha256', uniqid('', true)), 0, 10);
        putenv('WELINE_TEST_ROLLBACK_TABLE=' . $table);
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $connectionFactory->getConnector();
        $physical = $connector->formatTableName($table);
        $connector->query("CREATE TABLE {$physical} (id INTEGER NOT NULL PRIMARY KEY)")->fetch();
        $connector->getQuery()->clearQuery()->table($table)->insert(['id' => 7])->fetch();

        $migrationPath = BP . 'app/code/Weline/Test/Setup/Db/Migration/';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }
        $migrationFile = $migrationPath . 'rollback_backup_fixture_20260713-v1.1.0.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\RollbackBackupStrategyInterface;

final class RollbackBackupFixture20260713V110 extends AbstractMigration implements RollbackBackupStrategyInterface
{
    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    public function install(): bool
    {
        $table = (string)getenv('WELINE_TEST_ROLLBACK_TABLE');
        $connector = $this->connectionFactory->getConnector();
        if (!$connector->hasField($table, 'rollback_value')) {
            $connector->query($connector->buildAlterAddColumnSql($table, [
                'name' => 'rollback_value',
                'type' => 'varchar',
                'length' => 255,
                'nullable' => true,
                'primaryKey' => false,
                'autoIncrement' => false,
                'default' => null,
                'comment' => '',
                'unique' => false,
            ]))->fetch();
        }
        return true;
    }

    public function uninstall(): bool
    {
        $table = (string)getenv('WELINE_TEST_ROLLBACK_TABLE');
        $connector = $this->connectionFactory->getConnector();
        if ($connector->hasField($table, 'rollback_value')) {
            $connector->query($connector->buildAlterDropColumnSql($table, 'rollback_value'))->fetch();
        }
        return true;
    }

    public function getVersion(): string
    {
        return '1.1.0';
    }

    public function getRollbackBackupStrategy(): array
    {
        return [
            'strategy' => 'column',
            'tables' => [(string)getenv('WELINE_TEST_ROLLBACK_TABLE')],
            'columns' => ['rollback_value'],
            'reason' => 'The column is introduced by this migration and is removed during rollback.',
        ];
    }
}
PHP
        );

        self::assertTrue($this->migrationService->upgradeMigration('Weline_Test', $migrationFile));
        self::assertTrue($connector->hasField($table, 'rollback_value'));
        $connector->getQuery()->clearQuery()->table($table)
            ->where('id', 7)
            ->update(['rollback_value' => 'value-written-on-1.1'])
            ->fetch();

        $plan = $this->migrationService->planRollbackToVersion('Weline_Test', '1.0.0', '1.1.0');
        self::assertSame([], $plan['blockers']);
        self::assertCount(1, $plan['migrations']);
        self::assertSame('column', $plan['migrations'][0]['rollback_backup_strategy']['strategy']);
        $completed = $this->migrationService->executeRollbackPlan(
            'Weline_Test',
            $plan['migrations'],
            'op-script-column-restore',
        );
        self::assertCount(1, $completed);
        self::assertFalse($connector->hasField($table, 'rollback_value'));

        self::assertTrue($this->migrationService->upgradeMigration('Weline_Test', $migrationFile));
        self::assertTrue($connector->hasField($table, 'rollback_value'));
        $rows = $connector->getQuery()->clearQuery()->table($table)
            ->fields(['rollback_value'])
            ->where('id', 7)
            ->limit(1)
            ->select()
            ->fetch();
        self::assertSame('value-written-on-1.1', $rows[0]['rollback_value'] ?? null);

        $backups = ObjectManager::getInstance(MigrationBackup::class, [], false)
            ->where(MigrationBackup::schema_fields_OPERATION_ID, 'op-script-column-restore')
            ->where(MigrationBackup::schema_fields_BACKUP_SCOPE, MigrationBackup::SCOPE_ROLLBACK)
            ->select()
            ->fetch()
            ->getItems();
        self::assertNotEmpty($backups);
        self::assertSame(
            MigrationBackup::RETENTION_EXPIRING,
            $backups[0]->getData(MigrationBackup::schema_fields_RETENTION_STATE),
        );
    }

    public function testLegacyScriptWithoutRollbackBackupContractIsBlockedByPreflight(): void
    {
        $migration = $this->createMock(MigrationInterface::class);
        $method = (new \ReflectionClass($this->migrationService))
            ->getMethod('resolveRollbackBackupStrategy');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RollbackBackupStrategyInterface');
        $method->invoke($this->migrationService, $migration);
    }
    
    /**
     * 测试迁移记录
     */
    public function testRecordMigration()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 创建模拟的迁移类
        $mockMigration = $this->createMock(MigrationInterface::class);
        $mockMigration->method('getInfo')->willReturn([]);
        $mockMigration->method('getVersion')->willReturn('1.0.0');
        $mockMigration->method('getDescription')->willReturn('测试迁移');
        $mockMigration->method('getDependencies')->willReturn([]);
        
        // 创建临时文件用于测试
        $tempFile = tempnam(sys_get_temp_dir(), 'test_migration');
        file_put_contents($tempFile, 'test content');
        
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('recordMigration');
        $method->setAccessible(true);
        
        // 执行记录迁移
        $method->invoke($this->migrationService, $moduleName, $tempFile, $mockMigration);
        
        // 验证迁移是否被记录
        $exists = $this->migrationModel->isMigrationExists($moduleName, basename($tempFile));
        $this->assertTrue($exists, '迁移应该被记录');
        
        // 清理临时文件
        unlink($tempFile);
    }
    
    /**
     * 测试更新迁移状态
     */
    public function testUpdateMigrationStatus()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 先清理可能遗留的测试数据
        $this->migrationModel->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, $migrationFile)
            ->delete()
            ->fetch();
        
        // 记录一个新迁移
        $testData = [
            'module_name' => $moduleName,
            'version' => '1.0.0',
            'migration_file' => $migrationFile,
            'description' => '测试迁移',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $this->migrationModel->recordMigration($testData);
        
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('updateMigrationStatus');
        $method->setAccessible(true);
        
        // 更新状态
        $method->invoke($this->migrationService, $moduleName, $migrationFile, Migration::STATUS_ROLLED_BACK);
        
        // 验证状态已更新
        $items = $this->migrationModel->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $migration = $items[0] ?? null;
        $this->assertNotNull($migration);
        $this->assertEquals(Migration::STATUS_ROLLED_BACK, $migration->getData(Migration::schema_fields_STATUS));
    }

    private function removeFixtureMigration(): void
    {
        $migrationPath = BP . 'app/code/Weline/Test/Setup/Db/Migration/';
        foreach ([
            $migrationPath . 'create_table__test_20250101-v1.0.0.php',
            $migrationPath . 'rollback_backup_fixture_20260713-v1.1.0.php',
        ] as $migrationFile) {
            if (is_file($migrationFile)) {
                unlink($migrationFile);
            }
        }

        foreach ([
            rtrim($migrationPath, '/'),
            dirname(rtrim($migrationPath, '/')),
            dirname(dirname(rtrim($migrationPath, '/'))),
            dirname(dirname(dirname(rtrim($migrationPath, '/')))),
        ] as $directory) {
            if (is_dir($directory) && count(scandir($directory) ?: []) === 2) {
                rmdir($directory);
            }
        }
    }
}
