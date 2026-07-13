<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Service\VersionService;
use Weline\Database\Model\ModuleVersion;
use Weline\Database\Model\ModuleVersionHistory;

/**
 * 版本管理服务测试
 */
class VersionServiceTest extends TestCore
{
    private VersionService $versionService;
    private ModuleVersion $versionModel;
    /** @var list<string> */
    private array $fixtureModules = [];
    
    public function setUp(): void
    {
        parent::setUp();
        $this->versionService = ObjectManager::getInstance(VersionService::class);
        $this->versionModel = ObjectManager::getInstance(ModuleVersion::class);
    }
    
    public function tearDown(): void
    {
        foreach ($this->fixtureModules as $moduleName) {
            ObjectManager::getInstance(ModuleVersion::class, [], false)
                ->where(ModuleVersion::schema_fields_MODULE_NAME, $moduleName)
                ->delete()
                ->fetch();
            ObjectManager::getInstance(ModuleVersionHistory::class, [], false)
                ->where(ModuleVersionHistory::schema_fields_MODULE_NAME, $moduleName)
                ->delete()
                ->fetch();
        }
        parent::tearDown();
    }
    
    /**
     * 测试服务初始化
     */
    public function testServiceInitialization()
    {
        $this->assertInstanceOf(VersionService::class, $this->versionService);
    }
    
    /**
     * 测试获取模块版本
     */
    public function testGetModuleVersion()
    {
        $moduleName = $this->fixtureModule('get');
        
        $this->assertNull($this->versionService->getModuleVersion($moduleName));
        $this->assertTrue($this->versionService->setModuleVersion($moduleName, '1.0.0'));
        $this->assertInstanceOf(ModuleVersion::class, $this->versionService->getModuleVersion($moduleName));
    }
    
    /**
     * 测试设置模块版本
     */
    public function testSetModuleVersion()
    {
        $moduleName = $this->fixtureModule('set');
        $version = '1.0.0';
        
        // 测试设置模块版本
        $result = $this->versionService->setModuleVersion($moduleName, $version);
        $this->assertTrue($result, '设置模块版本应该成功');
        
        // 验证版本是否设置成功
        $actualVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals($version, $actualVersion);
    }
    
    /**
     * 测试版本比较
     */
    public function testCompareVersions()
    {
        // 测试版本比较
        $result1 = $this->versionService->compareVersions('1.0.0', '1.0.1');
        $this->assertEquals(-1, $result1, '1.0.0应该小于1.0.1');
        
        $result2 = $this->versionService->compareVersions('1.0.1', '1.0.0');
        $this->assertEquals(1, $result2, '1.0.1应该大于1.0.0');
        
        $result3 = $this->versionService->compareVersions('1.0.0', '1.0.0');
        $this->assertEquals(0, $result3, '1.0.0应该等于1.0.0');
    }
    
    /**
     * 测试检查版本更新
     */
    public function testCheckVersionUpdate()
    {
        $moduleName = $this->fixtureModule('changed');
        $currentVersion = '1.0.0';
        $newVersion = '1.0.1';
        
        // 设置当前版本
        $this->versionService->setModuleVersion($moduleName, $currentVersion);
        
        // 测试检查版本更新
        $result = $this->versionService->checkVersionUpdate($moduleName, $newVersion);
        $this->assertTrue($result, '应该检测到版本更新');
    }
    
    /**
     * 测试获取所有模块版本
     */
    public function testGetAllModuleVersions()
    {
        // 设置一些测试版本
        $moduleOne = $this->fixtureModule('all_one');
        $moduleTwo = $this->fixtureModule('all_two');
        $this->versionService->setModuleVersion($moduleOne, '1.0.0');
        $this->versionService->setModuleVersion($moduleTwo, '1.0.1');
        
        // 获取所有模块版本
        $versions = $this->versionService->getAllModuleVersions();
        $this->assertIsArray($versions);
        $this->assertGreaterThanOrEqual(2, count($versions));
    }
    
    /**
     * 测试版本升级
     */
    public function testUpgradeModuleVersion()
    {
        $moduleName = $this->fixtureModule('upgrade');
        $fromVersion = '1.0.0';
        $toVersion = '1.0.1';
        
        // 设置初始版本
        $this->versionService->setModuleVersion($moduleName, $fromVersion);
        
        // 测试版本升级
        $result = $this->versionService->upgradeModuleVersion($moduleName, $toVersion);
        $this->assertTrue($result, '版本升级应该成功');
        
        // 验证版本已升级
        $actualVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals($toVersion, $actualVersion);
    }
    
    /**
     * 测试版本回滚
     */
    public function testRollbackModuleVersion()
    {
        $moduleName = $this->fixtureModule('rollback');
        $fromVersion = '1.0.1';
        $toVersion = '1.0.0';
        
        // 设置初始版本
        $this->versionService->setModuleVersion($moduleName, $fromVersion);
        
        // 测试版本回滚
        $result = $this->versionService->rollbackModuleVersion($moduleName, $toVersion);
        $this->assertFalse($result, '单独修改数据库版本游标必须被拒绝');
        
        // 联动编排尚未执行，因此游标必须保持不变。
        $actualVersion = $this->versionService->getModuleVersionString($moduleName);
        $this->assertEquals($fromVersion, $actualVersion);
    }
    
    /**
     * 测试版本验证
     */
    public function testValidateVersion()
    {
        // 测试有效版本
        $result1 = $this->versionService->validateVersion('1.0.0');
        $this->assertTrue($result1, '1.0.0应该是有效版本');
        
        $result2 = $this->versionService->validateVersion('1.0.1');
        $this->assertTrue($result2, '1.0.1应该是有效版本');
        
        // 测试无效版本
        $result3 = $this->versionService->validateVersion('invalid');
        $this->assertFalse($result3, 'invalid应该是无效版本');
        
        $result4 = $this->versionService->validateVersion('1.0');
        $this->assertFalse($result4, '1.0应该是无效版本');
    }

    private function fixtureModule(string $suffix): string
    {
        $moduleName = 'Weline_Test_' . $suffix . '_' . substr(hash('sha256', uniqid('', true)), 0, 10);
        $this->fixtureModules[] = $moduleName;
        return $moduleName;
    }
}
