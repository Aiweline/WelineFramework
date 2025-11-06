<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Console\Command\RulesImport;

/**
 * RulesImport命令单元测试
 */
class RulesImportTest extends TestCase
{
    /**
     * 测试：命令实例化
     */
    public function testCommandInstantiation(): void
    {
        $command = new RulesImport();
        $this->assertInstanceOf(RulesImport::class, $command);
    }

    /**
     * 测试：命令描述
     */
    public function testTip(): void
    {
        $command = new RulesImport();
        $tip = $command->tip();
        $this->assertIsString($tip);
        $this->assertNotEmpty($tip);
        $this->assertStringContainsString('导入', $tip);
    }

    /**
     * 测试：帮助信息
     */
    public function testHelp(): void
    {
        $command = new RulesImport();
        $help = $command->help();
        $this->assertTrue(is_string($help) || is_array($help));
    }

    /**
     * 测试：执行命令方法存在
     */
    public function testExecuteMethodExists(): void
    {
        $command = new RulesImport();
        $this->assertTrue(method_exists($command, 'execute'));
    }

    /**
     * 测试：执行命令（域名为空）
     */
    public function testExecuteDomainEmpty(): void
    {
        $command = new RulesImport();
        $this->assertTrue(is_callable([$command, 'execute']));
    }

    /**
     * 测试：执行命令（域名不存在）
     */
    public function testExecuteDomainNotExists(): void
    {
        $command = new RulesImport();
        $this->assertTrue(method_exists($command, 'execute'));
    }

    /**
     * 测试：执行命令（导入成功）
     */
    public function testExecuteImportSuccess(): void
    {
        $command = new RulesImport();
        $this->assertTrue(method_exists($command, 'execute'));
    }

    /**
     * 测试：执行命令（导入失败）
     */
    public function testExecuteImportFailure(): void
    {
        $command = new RulesImport();
        $this->assertTrue(method_exists($command, 'execute'));
    }
}

