<?php
declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Database\Console\Db\Migrate\Upgrade;
use Weline\Database\Console\Db\Migrate\Rollback;
use Weline\Database\Console\Db\Migrate\Status;
use Weline\Database\Console\Db\Migrate\Uninstall;

/**
 * 数据库迁移控制台命令测试
 */
class ConsoleCommandTest extends TestCore
{
    /**
     * 测试升级命令初始化
     */
    public function testUpgradeCommandInitialization()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $this->assertInstanceOf(Upgrade::class, $upgradeCommand);
    }
    
    /**
     * 测试回滚命令初始化
     */
    public function testRollbackCommandInitialization()
    {
        $rollbackCommand = ObjectManager::getInstance(Rollback::class);
        $this->assertInstanceOf(Rollback::class, $rollbackCommand);
    }
    
    /**
     * 测试状态命令初始化
     */
    public function testStatusCommandInitialization()
    {
        $statusCommand = ObjectManager::getInstance(Status::class);
        $this->assertInstanceOf(Status::class, $statusCommand);
    }
    
    /**
     * 测试卸载命令初始化
     */
    public function testUninstallCommandInitialization()
    {
        $uninstallCommand = ObjectManager::getInstance(Uninstall::class);
        $this->assertInstanceOf(Uninstall::class, $uninstallCommand);
    }
    
    /**
     * 测试命令接口实现
     */
    public function testCommandInterfaceImplementation()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $rollbackCommand = ObjectManager::getInstance(Rollback::class);
        $statusCommand = ObjectManager::getInstance(Status::class);
        $uninstallCommand = ObjectManager::getInstance(Uninstall::class);
        
        // 检查是否实现了CommandInterface
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $upgradeCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $rollbackCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $statusCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $uninstallCommand);
    }
    
    /**
     * 测试命令提示信息
     */
    public function testCommandTips()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $rollbackCommand = ObjectManager::getInstance(Rollback::class);
        $statusCommand = ObjectManager::getInstance(Status::class);
        $uninstallCommand = ObjectManager::getInstance(Uninstall::class);
        
        // 检查命令提示信息
        $this->assertIsString($upgradeCommand->tip());
        $this->assertIsString($rollbackCommand->tip());
        $this->assertIsString($statusCommand->tip());
        $this->assertIsString($uninstallCommand->tip());
        
        // 检查提示信息不为空
        $this->assertNotEmpty($upgradeCommand->tip());
        $this->assertNotEmpty($rollbackCommand->tip());
        $this->assertNotEmpty($statusCommand->tip());
        $this->assertNotEmpty($uninstallCommand->tip());
    }
    
    /**
     * 测试命令执行方法存在
     */
    public function testCommandExecuteMethodExists()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $rollbackCommand = ObjectManager::getInstance(Rollback::class);
        $statusCommand = ObjectManager::getInstance(Status::class);
        $uninstallCommand = ObjectManager::getInstance(Uninstall::class);
        
        // 检查execute方法存在
        $this->assertTrue(method_exists($upgradeCommand, 'execute'));
        $this->assertTrue(method_exists($rollbackCommand, 'execute'));
        $this->assertTrue(method_exists($statusCommand, 'execute'));
        $this->assertTrue(method_exists($uninstallCommand, 'execute'));
    }
    
    /**
     * 测试命令执行方法签名
     */
    public function testCommandExecuteMethodSignature()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        
        // 通过反射检查execute方法签名
        $reflection = new \ReflectionClass($upgradeCommand);
        $executeMethod = $reflection->getMethod('execute');
        
        // 检查参数
        $parameters = $executeMethod->getParameters();
        $this->assertCount(2, $parameters, 'execute方法应该有2个参数');
        
        // 检查参数类型
        $this->assertEquals('args', $parameters[0]->getName());
        $this->assertEquals('data', $parameters[1]->getName());
        
        // 检查参数默认值
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
    }
    
    /**
     * 测试命令类命名空间
     */
    public function testCommandNamespaces()
    {
        $this->assertEquals('Weline\Database\Console\Db\Migrate', Upgrade::class);
        $this->assertEquals('Weline\Database\Console\Db\Migrate', Rollback::class);
        $this->assertEquals('Weline\Database\Console\Db\Migrate', Status::class);
        $this->assertEquals('Weline\Database\Console\Db\Migrate', Uninstall::class);
    }
    
    /**
     * 测试命令类继承
     */
    public function testCommandClassInheritance()
    {
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $rollbackCommand = ObjectManager::getInstance(Rollback::class);
        $statusCommand = ObjectManager::getInstance(Status::class);
        $uninstallCommand = ObjectManager::getInstance(Uninstall::class);
        
        // 检查类继承
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $upgradeCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $rollbackCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $statusCommand);
        $this->assertInstanceOf(\Weline\Framework\Console\CommandInterface::class, $uninstallCommand);
    }
}
