<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\VersionService;
use Weline\Database\Model\Migration;
use Weline\Database\Model\MigrationBackup;
use Weline\Database\Model\ModuleVersion;
use Weline\Database\Model\ModuleVersionHistory;
use Weline\Database\Interface\MigrationInterface;

/**
 * 数据库迁移系统综合测试
 */
class DatabaseMigrationSystemTest extends TestCore
{
    private const MODULE = 'Weline_FrameworkSystemTest';
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
        $this->cleanupFixtures();
    }
    
    public function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
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
     * 测试完整的迁移流程
     */
    public function testCompleteMigrationWorkflow()
    {
        $moduleName = self::MODULE;
        $version = '1.0.0';
        
        // 1. 设置模块版本
        $result1 = $this->versionService->setModuleVersion($moduleName, $version);
        $this->assertTrue($result1, '设置模块版本应该成功');
        
        // 2. 验证版本设置
        $actualVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals($version, $actualVersion);
        
        // 3. 创建测试迁移文件
        $migrationPath = "app/code/Weline/Test/Setup/Db/Migration/";
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }
        
        $testMigrationFile = $migrationPath . 'create_table__test_20250101-v1.0.0.php';
        $testMigrationContent = '<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Database\Interface\MigrationInterface;

class CreateTableTest20250101V100 implements MigrationInterface
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
        
        // 4. 获取待执行的迁移
        $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
        $this->assertIsArray($pendingMigrations);
        
        // 清理测试文件
        unlink($testMigrationFile);
        rmdir($migrationPath);
    }
    
    /**
     * 测试版本管理功能
     */
    public function testVersionManagement()
    {
        $moduleName = self::MODULE;
        
        // 测试版本设置和获取
        $this->versionService->setModuleVersion($moduleName, '1.0.0');
        $version = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals('1.0.0', $version);
        
        // 测试版本比较
        $compareResult = $this->versionService->compareVersions('1.0.0', '1.0.1');
        $this->assertEquals(-1, $compareResult);
        
        // 测试版本升级
        $upgradeResult = $this->versionService->upgradeModuleVersion($moduleName, '1.0.1');
        $this->assertTrue($upgradeResult);
        
        $newVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals('1.0.1', $newVersion);
        
        // 测试版本回滚
        $rollbackResult = $this->versionService->rollbackModuleVersion($moduleName, '1.0.0');
        $this->assertFalse($rollbackResult);
        
        $rolledBackVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals('1.0.1', $rolledBackVersion);
    }
    
    /**
     * 测试迁移记录管理
     */
    public function testMigrationRecordManagement()
    {
        $moduleName = self::MODULE;
        $migrationFile = 'test_migration.php';
        
        // 测试记录迁移
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
        
        $recordResult = $this->migrationModel->recordMigration($testData);
        $this->assertGreaterThan(0, $recordResult, '记录迁移应该返回有效 ID');
        
        // 测试检查迁移是否存在
        $exists = $this->migrationModel->isMigrationExists($moduleName, $migrationFile);
        $this->assertTrue($exists, '迁移应该存在');
        
        // 测试获取模块迁移
        $migrations = $this->migrationModel->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        
        // 测试获取已安装的迁移
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        $this->assertIsArray($installedMigrations);
        
        // 测试更新迁移状态
        $record = clone $this->migrationModel;
        $record->load($recordResult);
        $updateResult = $record->updateStatus(Migration::STATUS_ROLLED_BACK);
        $this->assertTrue($updateResult, '更新状态应该成功');
        
        // 测试获取迁移统计
        $stats = $this->migrationModel->getMigrationStats($moduleName);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('installed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('pending', $stats);
    }
    
    /**
     * 测试备份管理功能
     */
    public function testBackupManagement()
    {
        $migrationId = 991340;
        $this->assertSame([], $this->backupService->getBackupsByMigrationId($migrationId));
        $this->assertSame(0, $this->backupService->getBackupStats($migrationId)['total']);
        $this->assertTrue($this->backupService->cleanupBackupData($migrationId));
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
        
        $invalidVersion = $this->versionService->validateVersion('invalid');
        $this->assertFalse($invalidVersion, 'invalid应该是无效版本');
        
        // 测试清理过期备份
        $cleanupResult = $this->backupModel->cleanupExpiredBackups(30);
        $this->assertIsInt($cleanupResult);
    }
    
    /**
     * 测试错误处理
     */
    public function testErrorHandling()
    {
        $moduleName = 'NonExistent_Module';
        
        // 测试不存在的模块
        $migrations = $this->migrationService->getModuleMigrations($moduleName);
        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations, '不存在的模块应该返回空数组');
        
        $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
        $this->assertIsArray($pendingMigrations);
        $this->assertEmpty($pendingMigrations, '不存在的模块应该返回空数组');
        
        // 测试不存在的备份
        $backupList = $this->backupService->getBackupsByMigrationId(991341);
        $this->assertSame([], $backupList);
        
        // 测试不存在的版本
        $version = $this->versionService->getModuleVersion($moduleName);
        $this->assertNull($version);
    }

    private function cleanupFixtures(): void
    {
        ObjectManager::getInstance(Migration::class, [], false)
            ->where(Migration::schema_fields_MODULE, self::MODULE)
            ->delete()
            ->fetch();
        ObjectManager::getInstance(ModuleVersion::class, [], false)
            ->where(ModuleVersion::schema_fields_MODULE_NAME, self::MODULE)
            ->delete()
            ->fetch();
        ObjectManager::getInstance(ModuleVersionHistory::class, [], false)
            ->where(ModuleVersionHistory::schema_fields_MODULE_NAME, self::MODULE)
            ->delete()
            ->fetch();
        $migrationFile = BP . 'app/code/Weline/Test/Setup/Db/Migration/create_table__test_20250101-v1.0.0.php';
        if (is_file($migrationFile)) {
            unlink($migrationFile);
        }
    }
}
