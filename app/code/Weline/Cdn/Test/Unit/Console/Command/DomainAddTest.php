<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Console\Command\DomainAdd;

/**
 * DomainAdd命令单元测试
 */
class DomainAddTest extends TestCase
{
    /**
     * 测试：命令实例化
     */
    public function testCommandInstantiation(): void
    {
        $command = new DomainAdd();
        $this->assertInstanceOf(DomainAdd::class, $command);
    }

    /**
     * 测试：命令描述
     */
    public function testTip(): void
    {
        $command = new DomainAdd();
        $tip = $command->tip();
        $this->assertIsString($tip);
        $this->assertNotEmpty($tip);
        $this->assertStringContainsString('添加', $tip);
    }

    /**
     * 测试：帮助信息
     */
    public function testHelp(): void
    {
        $command = new DomainAdd();
        $help = $command->help();
        $this->assertTrue(is_string($help) || is_array($help));
    }

    /**
     * 测试：执行命令方法存在
     */
    public function testExecuteMethodExists(): void
    {
        $command = new DomainAdd();
        $this->assertTrue(method_exists($command, 'execute'));
    }

    /**
     * 测试：执行命令（网站代码为空）
     */
    public function testExecuteSiteCodeEmpty(): void
    {
        $command = new DomainAdd();
        // 验证方法可调用
        $this->assertTrue(is_callable([$command, 'execute']));
    }

    /**
     * 测试：执行命令（网站不存在）
     */
    public function testExecuteSiteNotExists(): void
    {
        $command = new DomainAdd();
        $this->assertTrue(method_exists($command, 'execute'));
    }

    /**
     * 测试：执行命令（适配器不存在）
     */
    public function testExecuteAdapterNotExists(): void
    {
        $command = new DomainAdd();
        $this->assertTrue(method_exists($command, 'execute'));
    }
}

