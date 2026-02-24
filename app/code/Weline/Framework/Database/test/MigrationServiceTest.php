<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\MigrationService;
use Weline\Database\Model\Migration;
use Weline\Database\Interface\MigrationInterface;

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
        parent::tearDown();
        // 清理测试数据
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
        $expectedPath = 'app/code/Weline/Test/Setup/Db/Migration/';
        
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
        
        // 通过反射访问私有方法
        $reflection = new \ReflectionClass($this->migrationService);
        $method = $reflection->getMethod('updateMigrationStatus');
        $method->setAccessible(true);
        
        // 更新状态
        $method->invoke($this->migrationService, $moduleName, $migrationFile, Migration::STATUS_ROLLED_BACK);
        
        // 验证状态已更新
        $collection = $this->migrationModel->getCollection();
        $collection->addFieldToFilter(Migration::fields_MODULE, $moduleName);
        $collection->addFieldToFilter(Migration::fields_FILE, $migrationFile);
        
        $migration = $collection->getFirstItem();
        $this->assertEquals(Migration::STATUS_ROLLED_BACK, $migration->getData(Migration::fields_STATUS));
    }
}
