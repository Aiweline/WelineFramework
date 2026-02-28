<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\VersionService;
use Weline\Database\Model\Migration;
use Weline\Database\Model\MigrationBackup;
use Weline\Database\Model\ModuleVersion;

/**
 * 数据库迁移系统综合测试
 */
class DatabaseMigrationSystemTest extends TestCore
{
    private MigrationService $migrationService;
    private BackupService $backupService;
    private VersionService $versionService;
    private Migration $migrationModel;
    private MigrationBackup $backupModel;
    private ModuleVersion $versionModel;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->migrationService = ObjectManager::getInstance(MigrationService::class);
        $this->backupService = ObjectManager::getInstance(BackupService::class);
        $this->versionService = ObjectManager::getInstance(VersionService::class);
        $this->migrationModel = ObjectManager::getInstance(Migration::class);
        $this->backupModel = ObjectManager::getInstance(MigrationBackup::class);
        $this->versionModel = ObjectManager::getInstance(ModuleVersion::class);
    }
    
    public function tearDown(): void
    {
        parent::tearDown();
        // 清理测试数据
        $this->migrationModel->reset()
            ->where(Migration::fields_MODULE, 'Weline_SystemTest')
            ->delete()
            ->fetch();
    }
    
    /**
     * 测试系统组件初始化
     */
    public function testSystemComponentsInitialization()
    {
        $this->assertInstanceOf(MigrationService::class, $this->migrationService);
        $this->assertInstanceOf(BackupService::class, $this->backupService);
        $this->assertInstanceOf(VersionService::class, $this->versionService);
        $this->assertInstanceOf(Migration::class, $this->migrationModel);
        $this->assertInstanceOf(MigrationBackup::class, $this->backupModel);
        $this->assertInstanceOf(ModuleVersion::class, $this->versionModel);
    }
    
    /**
     * 测试完整的版本管理流程
     */
    public function testCompleteMigrationWorkflow()
    {
        $moduleName = 'Weline_SystemTest';
        $version = '1.0.0';
        
        // 设置模块版本
        $result = $this->versionService->setModuleVersion($moduleName, $version);
        $this->assertTrue($result, '设置模块版本应该成功');
        
        // 验证版本设置（使用 getModuleVersionString）
        $actualVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals($version, $actualVersion);
        
        // 验证版本变更检测（checkVersionUpdate 检查版本是否变化，不是检查是否更高）
        $hasChanged = $this->versionService->checkVersionUpdate($moduleName, '2.0.0');
        $this->assertTrue($hasChanged, '2.0.0 与 1.0.0 不同，应该返回 true');
        
        $hasChangedLower = $this->versionService->checkVersionUpdate($moduleName, '0.9.0');
        $this->assertTrue($hasChangedLower, '0.9.0 与 1.0.0 不同，应该返回 true');
        
        $noChange = $this->versionService->checkVersionUpdate($moduleName, '1.0.0');
        $this->assertFalse($noChange, '1.0.0 与当前版本相同，应该返回 false');
    }
    
    /**
     * 测试版本管理功能
     */
    public function testVersionManagement()
    {
        $moduleName = 'Weline_SystemTest';
        
        // 设置版本
        $this->versionService->setModuleVersion($moduleName, '1.0.0');
        
        // 升级版本
        $upgradeResult = $this->versionService->upgradeModuleVersion($moduleName, '1.1.0');
        $this->assertTrue($upgradeResult, '升级版本应该成功');
        
        // 验证升级后版本
        $currentVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals('1.1.0', $currentVersion);
        
        // 回滚版本
        $rollbackResult = $this->versionService->rollbackModuleVersion($moduleName, '1.0.0');
        $this->assertTrue($rollbackResult, '回滚版本应该成功');
        
        // 验证回滚后版本
        $afterRollback = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals('1.0.0', $afterRollback);
    }
    
    /**
     * 测试迁移记录管理
     */
    public function testMigrationRecordManagement()
    {
        $moduleName = 'Weline_SystemTest';
        $migrationFile = 'system_test_migration.php';
        
        // 清理旧数据
        $this->migrationModel->reset()
            ->where(Migration::fields_MODULE, $moduleName)
            ->where(Migration::fields_FILE, $migrationFile)
            ->delete()
            ->fetch();
        
        // 记录迁移
        $testData = [
            'module_name' => $moduleName,
            'version' => '1.0.0',
            'migration_file' => $migrationFile,
            'description' => '系统测试迁移',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => 'system_test_checksum',
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $migrationId = $this->migrationModel->recordMigration($testData);
        $this->assertGreaterThan(0, $migrationId, '记录迁移应该返回有效ID');
        
        // 验证迁移是否存在
        $exists = $this->migrationModel->isMigrationExists($moduleName, $migrationFile);
        $this->assertTrue($exists, '迁移记录应该存在');
    }
    
    /**
     * 测试备份统计功能
     */
    public function testBackupManagement()
    {
        $migrationId = 99998; // 使用测试用迁移ID
        
        // 获取备份统计（使用实际存在的方法）
        $stats = $this->backupService->getBackupStats($migrationId);
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('tables', $stats);
        $this->assertArrayHasKey('columns', $stats);
        
        // 获取备份列表
        $backups = $this->backupService->getBackupsByMigrationId($migrationId);
        $this->assertIsArray($backups);
        
        // 清理备份
        $cleanupResult = $this->backupService->cleanupBackupData($migrationId);
        $this->assertIsBool($cleanupResult);
    }
    
    /**
     * 测试系统集成功能
     */
    public function testSystemIntegration()
    {
        // 测试获取所有模块版本
        $allVersions = $this->versionService->getAllModuleVersions();
        $this->assertIsArray($allVersions);
        
        // 测试版本验证
        $validVersion = $this->versionService->validateVersion('1.0.0');
        $this->assertTrue($validVersion, '1.0.0应该是有效版本');
        
        $validVersion2 = $this->versionService->validateVersion('2.3.4');
        $this->assertTrue($validVersion2, '2.3.4应该是有效版本');
        
        $invalidVersion = $this->versionService->validateVersion('invalid');
        $this->assertFalse($invalidVersion, 'invalid应该是无效版本');
        
        // 测试版本比较
        $compareResult = $this->versionService->compareVersions('1.0.0', '2.0.0');
        $this->assertEquals(-1, $compareResult, '1.0.0 应该小于 2.0.0');
        
        $compareResult2 = $this->versionService->compareVersions('2.0.0', '2.0.0');
        $this->assertEquals(0, $compareResult2, '相同版本应该相等');
    }
    
    /**
     * 测试错误处理
     */
    public function testErrorHandling()
    {
        $moduleName = 'NonExistent_Module';
        
        // 测试不存在的模块的迁移
        $migrations = $this->migrationService->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations, '不存在的模块应该返回空数组');
        
        $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
        $this->assertIsArray($pendingMigrations);
        $this->assertEmpty($pendingMigrations, '不存在的模块应该返回空数组');
        
        // 测试不存在的模块的版本
        $version = $this->versionService->getModuleVersion($moduleName);
        $this->assertNull($version, '不存在的模块版本应该返回 null');
        
        $versionString = $this->versionService->getModuleVersionString($moduleName);
        $this->assertNull($versionString, '不存在的模块版本字符串应该返回 null');
    }
}
