<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

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
    
    public function testCanonicalBackupApiIsAvailable(): void
    {
        $this->assertTrue(method_exists($this->backupService, 'backupTableData'));
        $this->assertTrue(method_exists($this->backupService, 'backupTableStructure'));
        $this->assertTrue(method_exists($this->backupService, 'backupColumnData'));
        $this->assertTrue(method_exists($this->backupService, 'restoreTableDataConflictSafe'));
        $this->assertTrue(method_exists($this->backupService, 'restoreColumnDataConflictSafe'));
    }

    public function testUnsafeLegacyGenericBackupApiIsNotExposed(): void
    {
        $this->assertFalse(method_exists($this->backupService, 'createBackup'));
        $this->assertFalse(method_exists($this->backupService, 'restoreBackup'));
        $this->assertFalse(method_exists($this->backupService, 'deleteBackup'));
    }

    public function testEmptyBackupCollectionAndCleanup(): void
    {
        $migrationId = 991339;
        $this->assertSame([], $this->backupService->getBackupsByMigrationId($migrationId));
        $stats = $this->backupService->getBackupStats($migrationId);
        $this->assertSame(0, $stats['total']);
        $this->assertTrue($this->backupService->cleanupBackupData($migrationId));
    }

    public function testRetentionCleanupIsOwnedByCanonicalModel(): void
    {
        $this->assertIsInt($this->backupModel->cleanupExpiredBackups(30));
    }
}
