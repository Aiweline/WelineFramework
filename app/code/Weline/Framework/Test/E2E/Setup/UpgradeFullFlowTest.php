<?php

declare(strict_types=1);

namespace Weline\Framework\Test\E2E\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Console\Setup\Upgrade;
use Weline\Framework\Output\Cli\Printing;

/**
 * 完整升级流程 E2E 测试
 *
 * 测试数据库升级的完整流程，包括：
 * - 新安装
 * - 添加字段
 * - 删除字段（带数据备份）
 * - 修改字段
 * - 大表升级
 * - 升级失败恢复
 */
class UpgradeFullFlowTest extends TestCase
{
    private Upgrade $upgradeCommand;
    private ConnectionFactory $connectionFactory;
    private Printing $printing;
    private string $testModule = 'Weline_TestUpgrade';

    protected function setUp(): void
    {
        parent::setUp();

        $this->upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $this->connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $this->printing = ObjectManager::getInstance(Printing::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * 测试：全新安装
     */
    public function testUpgrade_NewInstall(): void
    {
        // 执行升级（全新安装）
        $result = $this->runUpgrade();

        // 验证升级成功
        $this->assertTrue($result);

        // 验证测试表已创建
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasTable('test_upgrade_table'));

        // 验证表结构
        $columns = $connector->getTableColumns('test_upgrade_table');
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('status', $columnNames);
    }

    /**
     * 测试：添加字段
     */
    public function testUpgrade_AddColumn(): void
    {
        // 先执行初始安装
        $this->runUpgrade();

        // 插入测试数据
        $this->insertTestData(10);

        // 模拟升级：添加新字段
        $this->simulateAddColumnUpgrade();

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证新字段已添加
        $connector = $this->connectionFactory->getConnector();
        $this->assertTrue($connector->hasField('test_upgrade_table', 'description'));

        // 验证原有数据完整
        $query = $connector->getQuery();
        $count = $query->table('test_upgrade_table')->total();
        $this->assertEquals(10, $count);
    }

    /**
     * 测试：删除字段（有数据）
     */
    public function testUpgrade_DropColumn_WithData(): void
    {
        // 先执行初始安装
        $this->runUpgrade();

        // 插入测试数据
        $testData = [];
        for ($i = 1; $i <= 5; $i++) {
            $testData[$i] = "Important Data {$i}";
        }
        $this->insertTestDataWithStatus($testData);

        // 模拟升级：删除字段
        $this->simulateDropColumnUpgrade();

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证字段已删除
        $connector = $this->connectionFactory->getConnector();
        $this->assertFalse($connector->hasField('test_upgrade_table', 'status'));

        // 验证数据已备份
        $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\MigrationBackup::class);
        $backups = $backupModel->reset()
            ->where('table_name', 'test_upgrade_table')
            ->where('backup_type', \Weline\Framework\Setup\Model\MigrationBackup::TYPE_COLUMN)
            ->select()
            ->fetch()
            ->getItems();

        $this->assertNotEmpty($backups);

        // 验证备份数据完整性
        $backup = $backups[0];
        $backupData = json_decode($backup->getData('backup_data'), true);
        $this->assertCount(5, $backupData);

        foreach ($backupData as $row) {
            $id = $row['id'];
            $this->assertEquals($testData[$id], $row['status']);
        }
    }

    /**
     * 测试：修改字段
     */
    public function testUpgrade_ModifyColumn(): void
    {
        // 先执行初始安装
        $this->runUpgrade();

        // 插入测试数据
        $this->insertTestData(5);

        // 模拟升级：修改字段类型
        $this->simulateModifyColumnUpgrade();

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证字段类型已修改
        $connector = $this->connectionFactory->getConnector();
        $columns = $connector->getTableColumns('test_upgrade_table');
        $nameColumn = array_filter($columns, fn($col) => $col['name'] === 'name');
        $nameColumn = reset($nameColumn);

        // 验证长度已改变（从255到500）
        $this->assertGreaterThanOrEqual(500, $nameColumn['length'] ?? 0);

        // 验证数据完整
        $query = $connector->getQuery();
        $count = $query->table('test_upgrade_table')->total();
        $this->assertEquals(5, $count);
    }

    /**
     * 测试：大表升级（>10000行）
     */
    public function testUpgrade_LargeTable(): void
    {
        // 先执行初始安装
        $this->runUpgrade();

        // 插入大量数据
        $this->insertTestData(11000);

        // 模拟升级：删除字段（触发分块备份）
        $this->simulateDropColumnUpgrade();

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证分块备份
        $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\MigrationBackup::class);
        $chunks = $backupModel->reset()
            ->where('table_name', 'test_upgrade_table%', 'LIKE')
            ->where('backup_type', \Weline\Framework\Setup\Model\MigrationBackup::TYPE_CHUNK)
            ->select()
            ->fetch()
            ->getItems();

        // 验证分块数量（11000 / 1000 = 11块）
        $this->assertGreaterThanOrEqual(11, count($chunks));
    }

    /**
     * 测试：升级失败恢复
     */
    public function testUpgrade_FailureRecovery(): void
    {
        // 先执行初始安装
        $this->runUpgrade();

        // 插入测试数据
        $this->insertTestData(5);

        // 模拟升级失败场景（添加重复的唯一索引）
        $this->simulateFailedUpgrade();

        // 执行升级（预期失败）
        try {
            $this->runUpgrade();
            $this->fail('Expected upgrade to fail');
        } catch (\Exception $e) {
            // 预期异常
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }

        // 验证数据完整性（未被破坏）
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        $count = $query->table('test_upgrade_table')->total();
        $this->assertEquals(5, $count);

        // 验证迁移状态为失败
        $migrationModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class);
        $failedMigrations = $migrationModel->reset()
            ->where('module_name', $this->testModule)
            ->where('status', \Weline\Framework\Setup\Model\Migration::STATUS_FAILED)
            ->select()
            ->fetch()
            ->getItems();

        $this->assertNotEmpty($failedMigrations);
    }

    /**
     * 测试：多模块升级
     */
    public function testUpgrade_MultipleModules(): void
    {
        // 创建多个测试模块
        $modules = ['Weline_TestModule1', 'Weline_TestModule2', 'Weline_TestModule3'];

        foreach ($modules as $module) {
            $this->createTestModule($module);
        }

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证所有模块的表都已创建
        $connector = $this->connectionFactory->getConnector();
        foreach ($modules as $module) {
            $tableName = strtolower(str_replace('_', '_', $module)) . '_table';
            $this->assertTrue($connector->hasTable($tableName));
        }

        // 清理
        foreach ($modules as $module) {
            $this->cleanupTestModule($module);
        }
    }

    /**
     * 测试：依赖顺序
     */
    public function testUpgrade_DependencyOrder(): void
    {
        // 创建有依赖关系的模块
        $this->createDependentModules();

        // 执行升级
        $result = $this->runUpgrade();
        $this->assertTrue($result);

        // 验证依赖模块先执行
        $migrationModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class);
        $migrations = $migrationModel->reset()
            ->where('module_name', ['Weline_TestBase', 'Weline_TestDependent'], 'IN')
            ->order('executed_at', 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        // 验证基础模块先执行
        $this->assertEquals('Weline_TestBase', $migrations[0]->getData('module_name'));
        $this->assertEquals('Weline_TestDependent', $migrations[1]->getData('module_name'));

        // 清理
        $this->cleanupDependentModules();
    }

    // ==================== 辅助方法 ====================

    private function runUpgrade(): bool
    {
        try {
            // 执行升级命令
            $this->upgradeCommand->execute();
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__('升级失败: %{1}', $e->getMessage()));
            throw $e;
        }
    }

    private function insertTestData(int $count): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        for ($i = 1; $i <= $count; $i++) {
            $query->clearQuery()->table('test_upgrade_table')->insert([
                'name' => "Test {$i}",
                'status' => "Status {$i}"
            ])->fetch();
        }
    }

    private function insertTestDataWithStatus(array $statusData): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        foreach ($statusData as $id => $status) {
            $query->clearQuery()->table('test_upgrade_table')->insert([
                'id' => $id,
                'name' => "Test {$id}",
                'status' => $status
            ])->fetch();
        }
    }

    private function simulateAddColumnUpgrade(): void
    {
        // 模拟添加字段的升级脚本
        $connector = $this->connectionFactory->getConnector();
        $sql = "ALTER TABLE test_upgrade_table ADD COLUMN description TEXT";
        $connector->query($sql)->fetch();
    }

    private function simulateDropColumnUpgrade(): void
    {
        // 模拟删除字段的升级脚本
        // 实际会通过 SchemaMigrationExecutor 自动备份
        $connector = $this->connectionFactory->getConnector();
        $sql = "ALTER TABLE test_upgrade_table DROP COLUMN status";
        $connector->query($sql)->fetch();
    }

    private function simulateModifyColumnUpgrade(): void
    {
        // 模拟修改字段的升级脚本
        $connector = $this->connectionFactory->getConnector();
        $sql = "ALTER TABLE test_upgrade_table MODIFY COLUMN name VARCHAR(500)";
        $connector->query($sql)->fetch();
    }

    private function simulateFailedUpgrade(): void
    {
        // 模拟失败的升级（添加重复数据后创建唯一索引）
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        // 插入重复数据
        $query->table('test_upgrade_table')->insert([
            'name' => 'Duplicate',
            'status' => 'Active'
        ])->fetch();

        $query->clearQuery()->table('test_upgrade_table')->insert([
            'name' => 'Duplicate',
            'status' => 'Active'
        ])->fetch();

        // 尝试创建唯一索引（会失败）
        $sql = "ALTER TABLE test_upgrade_table ADD UNIQUE INDEX idx_unique_name (name)";
        $connector->query($sql)->fetch();
    }

    private function createTestModule(string $moduleName): void
    {
        // 创建测试模块的表
        $connector = $this->connectionFactory->getConnector();
        $tableName = strtolower(str_replace('_', '_', $moduleName)) . '_table';
        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255)
        )";
        $connector->query($sql)->fetch();
    }

    private function cleanupTestModule(string $moduleName): void
    {
        $connector = $this->connectionFactory->getConnector();
        $tableName = strtolower(str_replace('_', '_', $moduleName)) . '_table';
        $connector->dropTableIfExists($tableName);
    }

    private function createDependentModules(): void
    {
        // 创建基础模块
        $this->createTestModule('Weline_TestBase');

        // 创建依赖模块
        $this->createTestModule('Weline_TestDependent');
    }

    private function cleanupDependentModules(): void
    {
        $this->cleanupTestModule('Weline_TestBase');
        $this->cleanupTestModule('Weline_TestDependent');
    }

    private function cleanupTestData(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->dropTableIfExists('test_upgrade_table');

        // 清理备份数据
        $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\MigrationBackup::class);
        $backups = $backupModel->reset()
            ->where('table_name', 'test_upgrade_table%', 'LIKE')
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
