<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\BackupService;
use Weline\Database\Model\MigrationBackup;

/**
 * 数据库备份服务测试
 */
class BackupServiceTest extends TestCore
{
    private BackupService $backupService;
    private MigrationBackup $backupModel;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->backupService = ObjectManager::getInstance(BackupService::class);
        $this->backupModel = ObjectManager::getInstance(MigrationBackup::class);
    }
    
    public function tearDown(): void
    {
        parent::tearDown();
        // 清理测试数据
    }
    
    /**
     * 测试服务初始化
     */
    public function testServiceInitialization()
    {
        $this->assertInstanceOf(BackupService::class, $this->backupService);
    }
    
    /**
     * 测试创建备份
     */
    public function testCreateBackup()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        $backupData = [
            'table_name' => 'test_table',
            'data' => ['id' => 1, 'name' => 'test'],
            'structure' => 'CREATE TABLE test_table (id INT, name VARCHAR(255))'
        ];
        
        // 测试创建备份
        $result = $this->backupService->createBackup($moduleName, $migrationFile, $backupData);
        $this->assertTrue($result, '创建备份应该成功');
    }
    
    /**
     * 测试恢复备份
     */
    public function testRestoreBackup()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 先创建一个备份
        $backupData = [
            'table_name' => 'test_table',
            'data' => ['id' => 1, 'name' => 'test'],
            'structure' => 'CREATE TABLE test_table (id INT, name VARCHAR(255))'
        ];
        
        $this->backupService->createBackup($moduleName, $migrationFile, $backupData);
        
        // 测试恢复备份
        $result = $this->backupService->restoreBackup($moduleName, $migrationFile);
        $this->assertTrue($result, '恢复备份应该成功');
    }
    
    /**
     * 测试获取备份列表
     */
    public function testGetBackupList()
    {
        $moduleName = 'Weline_Test';
        
        // 创建一些测试备份
        $backupData1 = [
            'table_name' => 'test_table_1',
            'data' => ['id' => 1, 'name' => 'test1'],
            'structure' => 'CREATE TABLE test_table_1 (id INT, name VARCHAR(255))'
        ];
        
        $backupData2 = [
            'table_name' => 'test_table_2',
            'data' => ['id' => 2, 'name' => 'test2'],
            'structure' => 'CREATE TABLE test_table_2 (id INT, name VARCHAR(255))'
        ];
        
        $this->backupService->createBackup($moduleName, 'test_migration_1.php', $backupData1);
        $this->backupService->createBackup($moduleName, 'test_migration_2.php', $backupData2);
        
        // 获取备份列表
        $backups = $this->backupService->getBackupList($moduleName);
        $this->assertIsArray($backups);
        $this->assertGreaterThanOrEqual(2, count($backups));
    }
    
    /**
     * 测试删除备份
     */
    public function testDeleteBackup()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 先创建一个备份
        $backupData = [
            'table_name' => 'test_table',
            'data' => ['id' => 1, 'name' => 'test'],
            'structure' => 'CREATE TABLE test_table (id INT, name VARCHAR(255))'
        ];
        
        $this->backupService->createBackup($moduleName, $migrationFile, $backupData);
        
        // 测试删除备份
        $result = $this->backupService->deleteBackup($moduleName, $migrationFile);
        $this->assertTrue($result, '删除备份应该成功');
    }
    
    /**
     * 测试清理过期备份
     */
    public function testCleanupExpiredBackups()
    {
        $moduleName = 'Weline_Test';
        $days = 30;
        
        // 测试清理过期备份
        $result = $this->backupService->cleanupExpiredBackups($moduleName, $days);
        $this->assertTrue($result, '清理过期备份应该成功');
    }
    
    /**
     * 测试备份验证
     */
    public function testValidateBackup()
    {
        $moduleName = 'Weline_Test';
        $migrationFile = 'test_migration.php';
        
        // 先创建一个备份
        $backupData = [
            'table_name' => 'test_table',
            'data' => ['id' => 1, 'name' => 'test'],
            'structure' => 'CREATE TABLE test_table (id INT, name VARCHAR(255))'
        ];
        
        $this->backupService->createBackup($moduleName, $migrationFile, $backupData);
        
        // 测试备份验证
        $result = $this->backupService->validateBackup($moduleName, $migrationFile);
        $this->assertTrue($result, '备份验证应该成功');
    }
}
