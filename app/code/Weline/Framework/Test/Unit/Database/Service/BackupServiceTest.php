<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Manager\ObjectManager;

/**
 * BackupService 单元测试
 *
 * 测试表级备份和恢复的核心逻辑
 */
class BackupServiceTest extends TestCase
{
    private BackupService $service;
    private ConnectionFactory $connectionFactory;
    private MigrationBackup $backupModel;
    private Printing $printing;
    private string $testTable = 'test_backup_service_table';
    private int $testMigrationId = 99999;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $this->backupModel = ObjectManager::getInstance(MigrationBackup::class);
        $this->printing = ObjectManager::getInstance(Printing::class);

        $this->service = new BackupService(
            $this->connectionFactory,
            $this->backupModel,
            $this->printing
        );

        // 创建测试表
        $this->createTestTable();
    }

    protected function tearDown(): void
    {
        // 清理测试表和备份数据
        $this->dropTestTable();
        $this->cleanupBackupData();
        parent::tearDown();
    }

    /**
     * 测试：空表策略
     */
    public function testSmartBackupTable_EmptyTable(): void
    {
        // 不插入数据，直接备份
        $result = $this->service->smartBackupTable($this->testTable, $this->testMigrationId);

        // 验证策略为 empty
        $this->assertEquals('empty', $result['strategy']);
        $this->assertEquals(0, $result['total_rows']);
        $this->assertTrue($result['structure_backed_up']);
        $this->assertFalse($result['data_backed_up']);
    }

    /**
     * 测试：小表策略（全量备份）
     */
    public function testSmartBackupTable_SmallTable(): void
    {
        // 插入少量数据（< 10000行）
        $this->insertTestData(100);

        // 执行智能备份
        $result = $this->service->smartBackupTable($this->testTable, $this->testMigrationId);

        // 验证策略为 full
        $this->assertEquals('full', $result['strategy']);
        $this->assertEquals(100, $result['total_rows']);
        $this->assertTrue($result['structure_backed_up']);
        $this->assertTrue($result['data_backed_up']);

        // 验证备份数据
        $backups = $this->service->getBackupsByMigrationId($this->testMigrationId);
        $this->assertNotEmpty($backups);
    }

    /**
     * 测试：大表策略（分块备份）
     */
    public function testSmartBackupTable_LargeTable(): void
    {
        // 插入大量数据（> 10000行）
        $this->insertTestData(11000);

        // 执行智能备份
        $result = $this->service->smartBackupTable($this->testTable, $this->testMigrationId);

        // 验证策略为 chunked
        $this->assertEquals('chunked', $result['strategy']);
        $this->assertEquals(11000, $result['total_rows']);
        $this->assertTrue($result['structure_backed_up']);
        $this->assertTrue($result['data_backed_up']);

        // 验证分块备份
        $stats = $this->service->getBackupStats($this->testMigrationId);
        $this->assertGreaterThan(0, $stats['chunks']);
    }

    /**
     * 测试：指定主键备份列数据
     */
    public function testBackupColumnData_WithPrimaryKey(): void
    {
        // 插入测试数据
        $this->insertTestData(10);

        // 备份指定列
        $data = $this->service->backupColumnData(
            $this->testTable,
            'name',
            $this->testMigrationId
        );

        // 验证备份成功
        $this->assertIsArray($data);
        $this->assertCount(10, $data);

        // 验证备份内容
        foreach ($data as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    /**
     * 测试：自动推断主键
     */
    public function testBackupColumnData_AutoInferPrimaryKey(): void
    {
        // 插入测试数据
        $this->insertTestData(5);

        // 备份列数据（不指定主键，自动推断）
        $connector = $this->connectionFactory->getConnector();
        $data = $this->service->backupColumnData(
            $this->testTable,
            'description',
            $this->testMigrationId,
            $connector
        );

        // 验证备份成功
        $this->assertIsArray($data);
        $this->assertCount(5, $data);
    }

    /**
     * 测试：无主键表
     */
    public function testBackupColumnData_NoPrimaryKey(): void
    {
        // 创建无主键表
        $noPkTable = 'test_no_pk_table';
        $this->createNoPrimaryKeyTable($noPkTable);

        // 插入数据
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        for ($i = 1; $i <= 3; $i++) {
            $query->clearQuery()->table($noPkTable)->insert([
                'name' => "Test {$i}",
                'value' => $i * 100
            ])->fetch();
        }

        // 备份列数据
        $data = $this->service->backupColumnData(
            $noPkTable,
            'value',
            $this->testMigrationId,
            $connector
        );

        // 验证备份成功（使用第一列作为主键）
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        // 清理
        $connector->dropTableIfExists($noPkTable);
    }

    /**
     * 测试：成功恢复列数据
     */
    public function testRestoreColumnData_Success(): void
    {
        // 插入测试数据
        $this->insertTestData(5);

        // 备份列数据
        $this->service->backupColumnData(
            $this->testTable,
            'description',
            $this->testMigrationId
        );

        // 清空列数据
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        $query->table($this->testTable)->update(['description' => null])->fetch();

        // 恢复列数据
        $result = $this->service->restoreColumnData(
            $this->testTable,
            'description',
            $this->testMigrationId
        );

        $this->assertTrue($result);

        // 验证数据已恢复
        $data = $query->clearQuery()->table($this->testTable)->select()->fetch();
        foreach ($data as $row) {
            $this->assertNotNull($row['description']);
            $this->assertStringContainsString('Description', $row['description']);
        }
    }

    /**
     * 测试：数据完整性验证
     */
    public function testRestoreColumnData_DataIntegrity(): void
    {
        // 插入测试数据
        $originalData = [];
        for ($i = 1; $i <= 10; $i++) {
            $originalData[$i] = "Original Description {$i}";
        }
        $this->insertTestDataWithDescriptions($originalData);

        // 备份列数据
        $this->service->backupColumnData(
            $this->testTable,
            'description',
            $this->testMigrationId
        );

        // 清空列数据
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        $query->table($this->testTable)->update(['description' => null])->fetch();

        // 恢复列数据
        $this->service->restoreColumnData(
            $this->testTable,
            'description',
            $this->testMigrationId
        );

        // 验证数据完整性
        $data = $query->clearQuery()->table($this->testTable)->select()->fetch();
        foreach ($data as $row) {
            $id = $row['id'];
            $this->assertEquals($originalData[$id], $row['description']);
        }
    }

    /**
     * 测试：分块备份完整性
     */
    public function testBackupTableDataChunked_Integrity(): void
    {
        // 插入大量数据
        $this->insertTestData(2500);

        // 分块备份
        $result = $this->service->backupTableDataChunked(
            $this->testTable,
            $this->testMigrationId,
            1000
        );

        // 验证备份结果
        $this->assertEquals($this->testTable, $result['table']);
        $this->assertEquals(2500, $result['total_rows']);
        $this->assertEquals(3, $result['chunks']); // 2500 / 1000 = 3块
        $this->assertEquals(1000, $result['chunk_size']);
    }

    /**
     * 测试：分块恢复完整性
     */
    public function testRestoreTableDataChunked_Integrity(): void
    {
        // 插入测试数据
        $this->insertTestData(2500);

        // 分块备份
        $this->service->backupTableDataChunked(
            $this->testTable,
            $this->testMigrationId,
            1000
        );

        // 清空表数据
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();
        $query->table($this->testTable)->delete()->fetch();

        // 分块恢复
        $result = $this->service->restoreTableDataChunked(
            $this->testTable,
            $this->testMigrationId
        );

        $this->assertTrue($result);

        // 验证数据完整性
        $count = $query->clearQuery()->table($this->testTable)->total();
        $this->assertEquals(2500, $count);
    }

    /**
     * 测试：备份清理
     */
    public function testCleanupBackupData(): void
    {
        // 插入测试数据并备份
        $this->insertTestData(10);
        $this->service->backupTableData($this->testTable, $this->testMigrationId);

        // 验证备份存在
        $backups = $this->service->getBackupsByMigrationId($this->testMigrationId);
        $this->assertNotEmpty($backups);

        // 清理备份
        $result = $this->service->cleanupBackupData($this->testMigrationId);
        $this->assertTrue($result);

        // 验证备份已清理
        $backups = $this->service->getBackupsByMigrationId($this->testMigrationId);
        $this->assertEmpty($backups);
    }

    /**
     * 测试：备份统计
     */
    public function testGetBackupStats(): void
    {
        // 插入测试数据
        $this->insertTestData(100);

        // 执行多种备份
        $this->service->backupTableStructure($this->testTable, $this->testMigrationId);
        $this->service->backupTableData($this->testTable, $this->testMigrationId);
        $this->service->backupColumnData($this->testTable, 'name', $this->testMigrationId);

        // 获取统计
        $stats = $this->service->getBackupStats($this->testMigrationId);

        // 验证统计数据
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('tables', $stats);
        $this->assertArrayHasKey('columns', $stats);
        $this->assertArrayHasKey('structures', $stats);
        $this->assertArrayHasKey('total_records', $stats);

        $this->assertGreaterThan(0, $stats['total']);
        $this->assertEquals(1, $stats['structures']);
        $this->assertEquals(1, $stats['tables']);
        $this->assertEquals(1, $stats['columns']);
    }

    // ==================== 辅助方法 ====================

    private function createTestTable(): void
    {
        $connector = $this->connectionFactory->getConnector();

        $create = $connector->createTable();
        $create->createTable($this->testTable, 'Test table for backup service');
        $create->addColumn('id', 'INTEGER', null, 'PRIMARY KEY AUTO_INCREMENT', 'ID');
        $create->addColumn('name', 'VARCHAR', 255, 'NOT NULL', 'Name');
        $create->addColumn('description', 'TEXT', null, '', 'Description');
        $create->addColumn('created_at', 'TIMESTAMP', null, 'DEFAULT CURRENT_TIMESTAMP', 'Created At');
        $create->addAdditional('');
        $create->create();
    }

    private function createNoPrimaryKeyTable(string $tableName): void
    {
        $connector = $this->connectionFactory->getConnector();

        $create = $connector->createTable();
        $create->createTable($tableName, 'Test table without primary key');
        $create->addColumn('name', 'VARCHAR', 255, '', 'Name');
        $create->addColumn('value', 'INTEGER', null, '', 'Value');
        $create->addAdditional('');
        $create->create();
    }

    private function dropTestTable(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->dropTableIfExists($this->testTable);
    }

    private function insertTestData(int $count): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        for ($i = 1; $i <= $count; $i++) {
            $query->clearQuery()->table($this->testTable)->insert([
                'name' => "Test {$i}",
                'description' => "Description {$i}"
            ])->fetch();
        }
    }

    private function insertTestDataWithDescriptions(array $descriptions): void
    {
        $connector = $this->connectionFactory->getConnector();
        $query = $connector->getQuery();

        foreach ($descriptions as $id => $description) {
            $query->clearQuery()->table($this->testTable)->insert([
                'id' => $id,
                'name' => "Test {$id}",
                'description' => $description
            ])->fetch();
        }
    }

    private function cleanupBackupData(): void
    {
        $this->service->cleanupBackupData($this->testMigrationId);
    }
}
