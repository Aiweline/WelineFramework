<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use Weline\Framework\Test\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Model\Migration;
use Weline\Framework\Database\Schema\SchemaCheckpointDataException;

/**
 * 数据库迁移模型测试
 */
class MigrationTest extends TestCore
{
    private Migration $migrationModel;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->migrationModel = ObjectManager::getInstance(Migration::class, [], false);
        $this->cleanupMigrationFixtures();
    }
    
    public function tearDown(): void
    {
        $this->cleanupMigrationFixtures();
        parent::tearDown();
    }
    
    /**
     * 测试模型初始化
     */
    public function testModelInitialization()
    {
        $this->assertInstanceOf(Migration::class, $this->migrationModel);
        $this->assertStringContainsString('weline_database_migrations', $this->migrationModel->getTable());
        $this->assertEquals('migration_id', $this->migrationModel->getPrimaryKey());
    }
    
    /**
     * 测试状态常量
     */
    public function testStatusConstants()
    {
        $this->assertEquals('pending', Migration::STATUS_PENDING);
        $this->assertEquals('installed', Migration::STATUS_INSTALLED);
        $this->assertEquals('superseded', Migration::STATUS_SUPERSEDED);
        $this->assertEquals('rolled_back', Migration::STATUS_ROLLED_BACK);
        $this->assertEquals('failed', Migration::STATUS_FAILED);
    }
    
    /**
     * 测试字段常量
     */
    public function testFieldConstants()
    {
        $this->assertEquals('migration_id', Migration::schema_fields_ID);
        $this->assertEquals('module_name', Migration::schema_fields_MODULE);
        $this->assertEquals('version', Migration::schema_fields_VERSION);
        $this->assertEquals('migration_file', Migration::schema_fields_FILE);
        $this->assertEquals('description', Migration::schema_fields_DESCRIPTION);
        $this->assertEquals('status', Migration::schema_fields_STATUS);
        $this->assertEquals('executed_at', Migration::schema_fields_EXECUTED_AT);
        $this->assertEquals('rollback_at', Migration::schema_fields_ROLLBACK_AT);
        $this->assertEquals('dependencies', Migration::schema_fields_DEPENDENCIES);
        $this->assertEquals('checksum', Migration::schema_fields_CHECKSUM);
        $this->assertEquals('created_at', Migration::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', Migration::schema_fields_UPDATED_AT);
    }
    
    /**
     * 测试记录迁移
     */
    public function testRecordMigration()
    {
        $testData = [
            'module_name' => 'Weline_Test',
            'version' => '1.0.0',
            'migration_file' => 'test_migration.php',
            'description' => '测试迁移',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $result = $this->migrationModel->recordMigration($testData);
        $this->assertGreaterThan(0, $result, '记录迁移应该返回有效 ID');
        
        // 验证数据是否正确保存
        $this->assertEquals($testData['module_name'], $this->migrationModel->getData(Migration::schema_fields_MODULE));
        $this->assertEquals($testData['version'], $this->migrationModel->getData(Migration::schema_fields_VERSION));
        $this->assertEquals($testData['migration_file'], $this->migrationModel->getData(Migration::schema_fields_FILE));
        $this->assertEquals($testData['description'], $this->migrationModel->getData(Migration::schema_fields_DESCRIPTION));
        $this->assertEquals($testData['status'], $this->migrationModel->getData(Migration::schema_fields_STATUS));
    }
    
    /**
     * 测试检查迁移是否存在
     */
    public function testIsMigrationExists()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 先记录一个迁移
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
        
        // 测试存在检查
        $exists = $this->migrationModel->isMigrationExists($moduleName, $migrationFile);
        $this->assertTrue($exists, '迁移应该存在');
        
        // 测试不存在的情况
        $notExists = $this->migrationModel->isMigrationExists($moduleName, 'non_existent.php');
        $this->assertFalse($notExists, '不存在的迁移应该返回false');
    }
    
    /**
     * 测试获取模块迁移
     */
    public function testGetModuleMigrations()
    {
        $moduleName = 'Weline_Test';
        
        // 添加测试数据
        $testData1 = [
            'module_name' => $moduleName,
            'version' => '1.0.0',
            'migration_file' => 'test_migration_1.php',
            'description' => '测试迁移1',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum_1',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $testData2 = [
            'module_name' => $moduleName,
            'version' => '1.0.1',
            'migration_file' => 'test_migration_2.php',
            'description' => '测试迁移2',
            'status' => Migration::STATUS_PENDING,
            'dependencies' => [],
            'checksum' => 'test_checksum_2',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $this->migrationModel->recordMigration($testData1);
        $this->migrationModel->recordMigration($testData2);
        
        // 获取模块迁移
        $migrations = $this->migrationModel->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        $this->assertGreaterThanOrEqual(2, count($migrations));
    }
    
    /**
     * 测试获取已安装的迁移
     */
    public function testGetInstalledMigrations()
    {
        $moduleName = 'Weline_Test';
        
        // 添加测试数据
        $testData1 = [
            'module_name' => $moduleName,
            'version' => '1.0.0',
            'migration_file' => 'test_migration_1.php',
            'description' => '测试迁移1',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum_1',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $testData2 = [
            'module_name' => $moduleName,
            'version' => '1.0.1',
            'migration_file' => 'test_migration_2.php',
            'description' => '测试迁移2',
            'status' => Migration::STATUS_PENDING,
            'dependencies' => [],
            'checksum' => 'test_checksum_2',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $this->migrationModel->recordMigration($testData1);
        $this->migrationModel->recordMigration($testData2);
        
        // 获取已安装的迁移
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        $this->assertIsArray($installedMigrations);
        
        // 检查只返回已安装的迁移
        foreach ($installedMigrations as $migration) {
            $this->assertEquals(Migration::STATUS_INSTALLED, $migration->getData(Migration::schema_fields_STATUS));
        }
    }
    
    /**
     * 测试更新迁移状态
     */
    public function testUpdateStatus()
    {
        $testData = [
            'module_name' => 'Weline_Test',
            'version' => '1.0.0',
            'migration_file' => 'test_migration.php',
            'description' => '测试迁移',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $migrationId = $this->migrationModel->recordMigration($testData);
        
        // 更新状态为回滚
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $migration->load($migrationId);
        $result = $migration->updateStatus(Migration::STATUS_ROLLED_BACK);
        $this->assertTrue($result, '更新状态应该成功');
        
        // 验证状态已更新
        $this->assertEquals(Migration::STATUS_ROLLED_BACK, $migration->getData(Migration::schema_fields_STATUS));
    }
    
    /**
     * 测试获取迁移统计信息
     */
    public function testGetMigrationStats()
    {
        $moduleName = 'Weline_Test';
        
        // 添加不同状态的测试数据
        $testData1 = [
            'module_name' => $moduleName,
            'version' => '1.0.0',
            'migration_file' => 'test_migration_1.php',
            'description' => '测试迁移1',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'test_checksum_1',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $testData2 = [
            'module_name' => $moduleName,
            'version' => '1.0.1',
            'migration_file' => 'test_migration_2.php',
            'description' => '测试迁移2',
            'status' => Migration::STATUS_FAILED,
            'dependencies' => [],
            'checksum' => 'test_checksum_2',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $this->migrationModel->recordMigration($testData1);
        $this->migrationModel->recordMigration($testData2);
        
        // 获取统计信息
        $stats = $this->migrationModel->getMigrationStats($moduleName);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('installed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('pending', $stats);
        
        $this->assertGreaterThanOrEqual(2, $stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['installed']);
        $this->assertGreaterThanOrEqual(1, $stats['failed']);
    }

    public function testSchemaCheckpointIgnoresLegacySupersededRevision(): void
    {
        $tables = ['weline_test_checkpoint' => hash('sha256', 'legacy-superseded')];
        $this->recordSchemaCheckpointFixture(Migration::STATUS_SUPERSEDED, $tables);
        $activeId = $this->recordSchemaCheckpointFixture(Migration::STATUS_INSTALLED, $tables);

        $checkpoint = $this->migrationModel->getSchemaCheckpoint('Weline_Test', '1.0.0');

        $this->assertNotNull($checkpoint);
        $this->assertSame($activeId, $checkpoint['migration_id']);
        $this->assertSame($tables, $checkpoint['tables']);
    }

    public function testSchemaCheckpointStillRejectsUnexpectedInactiveStatus(): void
    {
        $tables = ['weline_test_checkpoint' => hash('sha256', 'failed-checkpoint')];
        $this->recordSchemaCheckpointFixture(Migration::STATUS_FAILED, $tables);

        $this->expectException(SchemaCheckpointDataException::class);
        $this->migrationModel->getSchemaCheckpoint('Weline_Test', '1.0.0');
    }

    /**
     * @param array<string, string> $tables
     */
    private function recordSchemaCheckpointFixture(string $status, array $tables): int
    {
        $checksum = $this->migrationModel->schemaCheckpointChecksum(
            $tables,
            Migration::SCHEMA_CHECKPOINT_FORMAT_CURRENT,
        );

        return $this->migrationModel->recordMigration([
            'module_name' => 'Weline_Test',
            'version' => '1.0.0',
            'migration_file' => Migration::SCHEMA_CHECKPOINT_FILE,
            'description' => 'Schema checkpoint fixture',
            'status' => $status,
            'dependencies' => [],
            'checksum' => $checksum,
            'migration_type' => Migration::SCHEMA_CHECKPOINT_TYPE,
            'operation_kind' => Migration::SCHEMA_CHECKPOINT_OPERATION,
            'schema_after_checksum' => $checksum,
            'operation_payload' => json_encode([
                'format' => Migration::SCHEMA_CHECKPOINT_FORMAT_CURRENT,
                'tables' => $tables,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function cleanupMigrationFixtures(): void
    {
        ObjectManager::getInstance(Migration::class, [], false)
            ->where(Migration::schema_fields_MODULE, 'Weline_Test')
            ->delete()
            ->fetch();
    }
}
