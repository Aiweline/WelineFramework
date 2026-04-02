<?php

declare(strict_types=1);

namespace Weline\Framework\Test\E2E\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

/**
 * 回滚流程 E2E 测试
 *
 * 测试数据库回滚的完整流程，包括：
 * - 按步数回滚
 * - 回滚到指定版本
 * - 跨版本回滚
 * - 数据完整性验证
 * - 字段数据恢复
 * - 表结构恢复
 */
class RollbackFullFlowTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private Printing $printing;
    private string $testModule = 'Weline_TestRollback';
    private string $testTable = 'test_rollback_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $this->printing = ObjectManager::getInstance(Printing::class);

        // 创建测试表
        $this->createTestTable();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * 测试：按步数回滚
     */
    public function testRollback_BySteps(): void
    {
        // 执行3次升级
        $this->executeUpgrade('1.0.0', 'add_column_email');
        $this->executeUpgrade('1.0.1', 'add_column_phone');
        $this->executeUpgrade('1.0.2', 'add_column_address');

        // 验证所有字段都已添加
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasField($this->testTable, 'email'));
        $this->assertTrue($connector->hasField($this->testTable, 'phone'));
        $this->assertTrue($connector->hasField($this->testTable, 'address'));

        // 回滚2步
        $result = $this->rollbackSteps(2);
        $this->assertTrue($result);

        // 验证最后2个字段已删除
        $this->assertFalse($connector->hasField($this->testTable, 'phone'));
        $this->assertFalse($connector->hasField($this->testTable, 'address'));

        // 验证第一个字段仍存在
        $this->assertTrue($connector->hasField($this->testTable, 'email'));
    }

    /**
     * 测试：回滚到指定版本
     */
    public function testRollback_ToVersion(): void
    {
        // 执行多次升级
        $this->executeUpgrade('1.0.0', 'add_column_email');
        $this->executeUpgrade('1.0.1', 'add_column_phone');
        $this->executeUpgrade('1.0.2', 'add_column_address');
        $this->executeUpgrade('1.0.3', 'add_column_city');

        // 回滚到版本 1.0.1
        $result = $this->rollbackToVersion('1.0.1');
        $this->assertTrue($result);

        // 验证版本 1.0.2 和 1.0.3 的更改已回滚
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasField($this->testTable, 'email'));
        $this->assertTrue($connector->hasField($this->testTable, 'phone'));
        $this->assertFalse($connector->hasField($this->testTable, 'address'));
        $this->assertFalse($connector->hasField($this->testTable, 'city'));
    }

    /**
     * 测试：跨版本回滚
     */
    public function testRollback_CrossVersion(): void
    {
        // 执行多个版本的升级
        $this->executeUpgrade('1.0.0', 'add_column_email');
        $this->executeUpgrade('2.0.0', 'add_column_phone');
        $this->executeUpgrade('3.0.0', 'add_column_address');

        // 跨版本回滚到 1.0.0
        $result = $this->rollbackToVersion('1.0.0');
        $this->assertTrue($result);

        // 验证所有后续版本的更改已回滚
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasField($this->testTable, 'email'));
        $this->assertFalse($connector->hasField($this->testTable, 'phone'));
        $this->assertFalse($connector->hasField($this->testTable, 'address'));
    }

    /**
     * 测试：数据完整性验证
     */
    public function testRollback_DataIntegrity(): void
    {
        // 插入测试数据
        $originalData = [];
        for ($i = 1; $i <= 10; $i++) {
            $originalData[$i] = [
                'name' => "Test {$i}",
                'status' => "Status {$i}"
            ];
        }
        $this->insertTestData($originalData);

        // 执行升级（添加字段）
        $this->executeUpgrade('1.0.0', 'add_column_email');

        // 更新新字段的数据
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        for ($i = 1; $i <= 10; $i++) {
            $query->clearQuery()->table($this->testTable)
                ->where('id', $i)
                ->update(['email' => "test{$i}@example.com"])
                ->fetch();
        }

        // 回滚升级
        $result = $this->rollbackSteps(1);
        $this->assertTrue($result);

        // 验证原始数据完整性
        $data = $query->clearQuery()->table($this->testTable)->select()->fetch();
        $this->assertCount(10, $data);

        foreach ($data as $row) {
            $id = $row['id'];
            $this->assertEquals($originalData[$id]['name'], $row['name']);
            $this->assertEquals($originalData[$id]['status'], $row['status']);
        }
    }

    /**
     * 测试：字段数据恢复
     */
    public function testRollback_ColumnRestore(): void
    {
        // 插入测试数据
        $testData = [];
        for ($i = 1; $i <= 5; $i++) {
            $testData[$i] = "Important Status {$i}";
        }
        $this->insertTestDataWithStatus($testData);

        // 执行升级（删除字段，会自动备份）
        $this->executeUpgrade('1.0.0', 'drop_column_status');

        // 验证字段已删除
        $connector = $this->connectionFactory->getConnector();
        $this->assertFalse($connector->hasField($this->testTable, 'status'));

        // 回滚升级
        $result = $this->rollbackSteps(1);
        $this->assertTrue($result);

        // 验证字段已恢复
        $this->assertTrue($connector->hasField($this->testTable, 'status'));

        // 验证数据已恢复
        $query = $connector->getQuery();
        $data = $query->table($this->testTable)->select()->fetch();

        foreach ($data as $row) {
            $id = $row['id'];
            $this->assertEquals($testData[$id], $row['status']);
        }
    }

    /**
     * 测试：表结构恢复
     */
    public function testRollback_TableRestore(): void
    {
        // 执行升级（修改表结构）
        $this->executeUpgrade('1.0.0', 'modify_table_structure');

        // 验证表结构已修改
        $connector = $this->connectionFactory->getConnector();
        $columns = $connector->getTableColumns($this->testTable);
        $nameColumn = array_filter($columns, fn($col) => $col['name'] === 'name');
        $nameColumn = reset($nameColumn);
        $this->assertGreaterThanOrEqual(500, $nameColumn['length'] ?? 0);

        // 回滚升级
        $result = $this->rollbackSteps(1);
        $this->assertTrue($result);

        // 验证表结构已恢复
        $columns = $connector->getTableColumns($this->testTable);
        $nameColumn = array_filter($columns, fn($col) => $col['name'] === 'name');
        $nameColumn = reset($nameColumn);
        $this->assertEquals(255, $nameColumn['length'] ?? 0);
    }

    /**
     * 测试：部分迁移失败回滚
     */
    public function testRollback_PartialFailure(): void
    {
        // 插入测试数据
        $this->insertTestData([
            1 => ['name' => 'Test 1', 'status' => 'Active'],
            2 => ['name' => 'Test 2', 'status' => 'Active']
        ]);

        // 执行升级（第一步成功）
        $this->executeUpgrade('1.0.0', 'add_column_email');

        // 执行升级（第二步失败）
        try {
            $this->executeUpgrade('1.0.1', 'add_duplicate_unique_index');
            $this->fail('Expected upgrade to fail');
        } catch (\Exception $e) {
            // 预期失败
        }

        // 验证第一步的更改仍然存在
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasField($this->testTable, 'email'));

        // 回滚成功的迁移
        $result = $this->rollbackSteps(1);
        $this->assertTrue($result);

        // 验证已回滚到初始状态
        $this->assertFalse($connector->hasField($this->testTable, 'email'));

        // 验证数据完整性
        $query = $connector->getQuery();
        $count = $query->table($this->testTable)->total();
        $this->assertEquals(2, $count);
    }

    /**
     * 测试：升级失败后自动回滚
     */
    public function testRollback_AfterUpgradeFailure(): void
    {
        // 插入测试数据
        $this->insertTestData([
            1 => ['name' => 'Test 1', 'status' => 'Active']
        ]);

        // 记录初始状态
        $connector = $this->connectionFactory->getConnector();
        $initialColumns = $connector->getTableColumns($this->testTable);
        $initialColumnNames = array_column($initialColumns, 'name');

        // 执行会失败的升级
        try {
            $this->executeFailedUpgrade();
            $this->fail('Expected upgrade to fail');
        } catch (\Exception $e) {
            // 预期失败
        }

        // 验证表结构未改变（自动回滚）
        $currentColumns = $connector->getTableColumns($this->testTable);
        $currentColumnNames = array_column($currentColumns, 'name');
        $this->assertEquals($initialColumnNames, $currentColumnNames);

        // 验证数据完整性
        $query = $connector->getQuery();
        $data = $query->table($this->testTable)->select()->fetch();
        $this->assertCount(1, $data);
        $this->assertEquals('Test 1', $data[0]['name']);
    }

    // ==================== 辅助方法 ====================

    private function createTestTable(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->testTable} (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $connector->query($sql)->fetch();
    }

    private function executeUpgrade(string $version, string $operation): void
    {
        $connector = $this->connectionFactory->getConnector();

        switch ($operation) {
            case 'add_column_email':
                $sql = "ALTER TABLE {$this->testTable} ADD COLUMN email VARCHAR(255)";
                break;
            case 'add_column_phone':
                $sql = "ALTER TABLE {$this->testTable} ADD COLUMN phone VARCHAR(50)";
                break;
            case 'add_column_address':
                $sql = "ALTER TABLE {$this->testTable} ADD COLUMN address TEXT";
                break;
            case 'add_column_city':
                $sql = "ALTER TABLE {$this->testTable} ADD COLUMN city VARCHAR(100)";
                break;
            case 'drop_column_status':
                // 先备份数据
                $backupService = ObjectManager::getInstance(\Weline\Framework\Database\Service\BackupService::class);
                $migrationId = $this->createMigrationRecord($version, $operation);
                $backupService->backupColumnData($this->testTable, 'status', $migrationId);
                $sql = "ALTER TABLE {$this->testTable} DROP COLUMN status";
                break;
            case 'modify_table_structure':
                $sql = "ALTER TABLE {$this->testTable} MODIFY COLUMN name VARCHAR(500)";
                break;
            case 'add_duplicate_unique_index':
                $sql = "ALTER TABLE {$this->testTable} ADD UNIQUE INDEX idx_unique_name (name)";
                break;
            default:
                throw new \Exception("Unknown operation: {$operation}");
        }

        // 记录迁移
        $migrationId = $this->createMigrationRecord($version, $operation);

        // 执行DDL
        $connector->query($sql)->fetch();

        // 更新迁移状态
        $this->updateMigrationStatus($migrationId, \Weline\Framework\Setup\Model\Migration::STATUS_INSTALLED);
    }

    private function executeFailedUpgrade(): void
    {
        $connector = $this->connectionFactory->getConnector();

        // 添加字段（成功）
        $sql1 = "ALTER TABLE {$this->testTable} ADD COLUMN temp_field VARCHAR(255)";
        $connector->query($sql1)->fetch();

        // 添加重复的唯一索引（失败）
        $sql2 = "ALTER TABLE {$this->testTable} ADD UNIQUE INDEX idx_unique_name (name)";
        $connector->query($sql2)->fetch();
    }

    private function rollbackSteps(int $steps): bool
    {
        try {
            $migrationService = ObjectManager::getInstance(\Weline\Database\Service\MigrationService::class);
            $migrationService->rollbackSteps($this->testModule, $steps);
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__('回滚失败: %{1}', $e->getMessage()));
            return false;
        }
    }

    private function rollbackToVersion(string $version): bool
    {
        try {
            $migrationService = ObjectManager::getInstance(\Weline\Database\Service\MigrationService::class);
            $migrationService->rollbackToVersion($this->testModule, $version);
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__('回滚失败: %{1}', $e->getMessage()));
            return false;
        }
    }

    private function createMigrationRecord(string $version, string $operation): int
    {
        $migrationModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class);
        $migrationModel->reset()->setData([
            'module_name' => $this->testModule,
            'version' => $version,
            'migration_file' => $operation,
            'status' => \Weline\Framework\Setup\Model\Migration::STATUS_RUNNING,
            'schema_table_name' => $this->testTable,
            'executed_at' => date('Y-m-d H:i:s')
        ])->save();

        return (int) $migrationModel->getId();
    }

    private function updateMigrationStatus(int $migrationId, string $status): void
    {
        $migrationModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class);
        $migrationModel->load($migrationId)
            ->setData('status', $status)
            ->save();
    }

    private function insertTestData(array $data): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        foreach ($data as $id => $row) {
            $row['id'] = $id;
            $query->clearQuery()->table($this->testTable)->insert($row)->fetch();
        }
    }

    private function insertTestDataWithStatus(array $statusData): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        foreach ($statusData as $id => $status) {
            $query->clearQuery()->table($this->testTable)->insert([
                'id' => $id,
                'name' => "Test {$id}",
                'status' => $status
            ])->fetch();
        }
    }

    private function cleanupTestData(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->dropTableIfExists($this->testTable);

        // 清理备份数据
        $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\MigrationBackup::class);
        $backups = $backupModel->reset()
            ->where('table_name', $this->testTable . '%', 'LIKE')
            ->select()
            ->fetch()
            ->getItems();

        foreach ($backups as $backup) {
            $backup->delete();
        }

        // 清理迁移记录
        $migrationModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class);
        $migrations = $migrationModel->reset()
            ->where('module_name', $this->testModule)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($migrations as $migration) {
            $migration->delete();
        }
    }
}
