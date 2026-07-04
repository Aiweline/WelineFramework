<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Interface\MigrationInterface;

/**
 * 数据库迁移接口测试
 */
class MigrationInterfaceTest extends TestCore
{
    /**
     * 测试迁移接口定义
     */
    public function testMigrationInterfaceDefinition()
    {
        $reflection = new \ReflectionClass(MigrationInterface::class);
        
        // 检查接口方法
        $methods = $reflection->getMethods();
        $methodNames = array_map(fn($method) => $method->getName(), $methods);
        
        $expectedMethods = [
            'install',
            'uninstall', 
            'getInfo',
            'validate',
            'getDependencies',
            'getDescription',
            'getVersion',
            'getDate'
        ];
        
        foreach ($expectedMethods as $method) {
            $this->assertContains($method, $methodNames, "接口缺少方法: {$method}");
        }
        
        // 检查方法签名
        $installMethod = $reflection->getMethod('install');
        $this->assertEquals('bool', $installMethod->getReturnType()->getName());
        
        $uninstallMethod = $reflection->getMethod('uninstall');
        $this->assertEquals('bool', $uninstallMethod->getReturnType()->getName());
        
        $getInfoMethod = $reflection->getMethod('getInfo');
        $this->assertEquals('array', $getInfoMethod->getReturnType()->getName());
        
        $validateMethod = $reflection->getMethod('validate');
        $this->assertEquals('bool', $validateMethod->getReturnType()->getName());
        
        $getDependenciesMethod = $reflection->getMethod('getDependencies');
        $this->assertEquals('array', $getDependenciesMethod->getReturnType()->getName());
        
        $getDescriptionMethod = $reflection->getMethod('getDescription');
        $this->assertEquals('string', $getDescriptionMethod->getReturnType()->getName());
        
        $getVersionMethod = $reflection->getMethod('getVersion');
        $this->assertEquals('string', $getVersionMethod->getReturnType()->getName());
        
        $getDateMethod = $reflection->getMethod('getDate');
        $this->assertEquals('string', $getDateMethod->getReturnType()->getName());
    }
    
    /**
     * 测试接口是否为接口类型
     */
    public function testIsInterface()
    {
        $reflection = new \ReflectionClass(MigrationInterface::class);
        $this->assertTrue($reflection->isInterface(), 'MigrationInterface应该是接口类型');
    }
    
    /**
     * 测试接口命名空间
     */
    public function testInterfaceNamespace()
    {
        $this->assertEquals('Weline\Database\Interface\MigrationInterface', MigrationInterface::class);
    }
}
