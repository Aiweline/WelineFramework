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
        // 清理测试备份数据
        $this->backupModel->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, 99999)
            ->delete()
            ->fetch();
    }
    
    /**
     * 测试服务初始化
     */
    public function testServiceInitialization()
    {
        $this->assertInstanceOf(BackupService::class, $this->backupService);
    }
    
    /**
     * 测试获取备份统计信息
     */
    public function testGetBackupStats()
    {
        $migrationId = 99999; // 使用测试用的迁移 ID
        
        // 获取备份统计
        $stats = $this->backupService->getBackupStats($migrationId);
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('tables', $stats);
        $this->assertArrayHasKey('columns', $stats);
        $this->assertArrayHasKey('structures', $stats);
        $this->assertArrayHasKey('chunks', $stats);
        $this->assertArrayHasKey('total_records', $stats);
    }
    
    /**
     * 测试获取迁移相关的备份列表
     */
    public function testGetBackupsByMigrationId()
    {
        $migrationId = 99999;
        
        // 获取备份列表
        $backups = $this->backupService->getBackupsByMigrationId($migrationId);
        
        $this->assertIsArray($backups);
    }
    
    /**
     * 测试清理备份数据
     */
    public function testCleanupBackupData()
    {
        $migrationId = 99999;
        
        // 清理备份数据
        $result = $this->backupService->cleanupBackupData($migrationId);
        
        // 方法应该返回 bool
        $this->assertIsBool($result);
    }
    
    /**
     * 测试 MigrationBackup 模型的备份类型常量
     */
    public function testBackupTypeConstants()
    {
        // 验证备份类型常量存在
        $this->assertEquals('table', MigrationBackup::TYPE_TABLE);
        $this->assertEquals('column', MigrationBackup::TYPE_COLUMN);
        $this->assertEquals('structure', MigrationBackup::TYPE_STRUCTURE);
        $this->assertEquals('chunk', MigrationBackup::TYPE_CHUNK);
    }
    
    /**
     * 测试默认常量值
     */
    public function testDefaultConstants()
    {
        // 通过反射检查常量
        $reflection = new \ReflectionClass(BackupService::class);
        
        $this->assertTrue($reflection->hasConstant('DEFAULT_CHUNK_SIZE'));
        $this->assertTrue($reflection->hasConstant('LARGE_TABLE_THRESHOLD'));
    }
}
