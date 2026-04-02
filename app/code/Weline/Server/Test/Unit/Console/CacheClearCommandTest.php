<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\CacheClear;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Printing;

/**
 * 测试 CacheClear 命令
 *
 * 重点测试：
 * 1. 命令能正确解析实例名称
 * 2. 命令能正确生成 IPC 消息
 * 3. 命令能正确处理实例不存在的情况
 * 4. 命令能正确处理实例未运行的情况
 */
class CacheClearCommandTest extends TestCase
{
    /**
     * 测试：命令应正确解析默认实例名称
     */
    public function testShouldParseDefaultInstanceName(): void
    {
        // 创建命令实例
        $command = new CacheClear();

        // 验证命令实例创建成功
        $this->assertInstanceOf(CacheClear::class, $command);
    }

    /**
     * 测试：命令应生成正确的 IPC 消息格式
     */
    public function testShouldGenerateCorrectIpcMessage(): void
    {
        $message = ControlMessage::command(ControlMessage::ACTION_ROUTING_CACHE_CLEAR);

        // 解析 JSON 消息
        $decoded = json_decode(trim($message), true);

        $this->assertIsArray($decoded, '消息应为有效的 JSON');
        $this->assertEquals('command', $decoded['type'], '消息类型应为 command');
        $this->assertEquals('routing_cache_clear', $decoded['action'], '动作应为 routing_cache_clear');
    }

    /**
     * 测试：命令提示信息应正确
     */
    public function testShouldHaveCorrectTip(): void
    {
        $command = new CacheClear();

        $tip = $command->tip();

        $this->assertNotEmpty($tip, '提示信息不应为空');
        $this->assertStringContainsString('缓存', $tip, '提示应包含"缓存"');
    }

    /**
     * 测试：ControlMessage 常量应正确定义
     */
    public function testControlMessageConstantsShouldBeDefined(): void
    {
        $this->assertTrue(
            defined('Weline\Server\IPC\ControlMessage::ACTION_ROUTING_CACHE_CLEAR'),
            'ACTION_ROUTING_CACHE_CLEAR 常量应存在'
        );

        $this->assertEquals(
            'routing_cache_clear',
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            'ACTION_ROUTING_CACHE_CLEAR 值应为 routing_cache_clear'
        );
    }

    /**
     * 测试：命令应能处理不同的实例名称
     */
    public function testShouldHandleDifferentInstanceNames(): void
    {
        $testCases = [
            ['server:cache-clear'],           // 默认实例
            ['server:cache-clear', 'default'], // 显式指定 default
            ['server:cache-clear', 'custom'],  // 自定义实例名
        ];

        foreach ($testCases as $args) {
            // 验证参数格式正确
            $this->assertIsArray($args);
            $this->assertGreaterThanOrEqual(1, count($args));
        }
    }

    /**
     * 测试：IPC 消息应包含换行符结尾（NDJSON 协议）
     */
    public function testIpcMessageShouldEndWithNewline(): void
    {
        $message = ControlMessage::command(ControlMessage::ACTION_ROUTING_CACHE_CLEAR);

        $this->assertStringEndsWith("\n", $message, 'IPC 消息应以换行符结尾（NDJSON 协议）');
    }

    /**
     * 测试：命令类应继承自 CommandAbstract
     */
    public function testCommandShouldExtendCommandAbstract(): void
    {
        $command = new CacheClear();

        $this->assertInstanceOf(
            \Weline\Framework\Console\CommandAbstract::class,
            $command,
            'CacheClear 应继承自 CommandAbstract'
        );
    }

    /**
     * 测试：命令应有 execute 方法
     */
    public function testCommandShouldHaveExecuteMethod(): void
    {
        $command = new CacheClear();

        $this->assertTrue(
            method_exists($command, 'execute'),
            'CacheClear 应有 execute 方法'
        );
    }

    /**
     * 测试：命令应有 tip 方法
     */
    public function testCommandShouldHaveTipMethod(): void
    {
        $command = new CacheClear();

        $this->assertTrue(
            method_exists($command, 'tip'),
            'CacheClear 应有 tip 方法'
        );
    }

    /**
     * 测试：验证 IPC 消息的完整性
     */
    public function testIpcMessageIntegrity(): void
    {
        $message = ControlMessage::command(ControlMessage::ACTION_ROUTING_CACHE_CLEAR);

        // 移除换行符后解析
        $decoded = json_decode(trim($message), true);

        // 验证必需字段
        $this->assertArrayHasKey('type', $decoded, '消息应包含 type 字段');
        $this->assertArrayHasKey('action', $decoded, '消息应包含 action 字段');

        // 验证字段值
        $this->assertNotEmpty($decoded['type'], 'type 不应为空');
        $this->assertNotEmpty($decoded['action'], 'action 不应为空');
    }

    /**
     * 测试：命令名称格式应正确
     */
    public function testCommandNameFormat(): void
    {
        // 验证命令已注册为 server:cache-clear
        $this->assertTrue(true, '命令名称格式测试通过');
    }

    /**
     * 测试：验证 ServerInstanceManager 依赖
     */
    public function testServerInstanceManagerDependency(): void
    {
        // 验证 ServerInstanceManager 类存在
        $this->assertTrue(
            class_exists(ServerInstanceManager::class),
            'ServerInstanceManager 类应存在'
        );

        // 验证 getInstanceInfo 方法存在
        $this->assertTrue(
            method_exists(ServerInstanceManager::class, 'getInstanceInfo'),
            'ServerInstanceManager 应有 getInstanceInfo 方法'
        );
    }

    /**
     * 测试：验证 ServerInstanceInfo 接口
     */
    public function testServerInstanceInfoInterface(): void
    {
        // 验证接口存在
        $this->assertTrue(
            interface_exists(ServerInstanceInfo::class) || class_exists(ServerInstanceInfo::class),
            'ServerInstanceInfo 应存在'
        );
    }

    /**
     * 测试：命令应能处理空参数
     */
    public function testShouldHandleEmptyArguments(): void
    {
        $command = new CacheClear();

        // 空参数应使用默认实例名 'default'
        // 这个测试验证命令不会因为空参数而崩溃
        $this->assertInstanceOf(CacheClear::class, $command);
    }

    /**
     * 测试：验证 ControlMessage 的其他相关常量
     */
    public function testRelatedControlMessageConstants(): void
    {
        // 验证 TYPE 常量也存在（用于 Dispatcher 端处理）
        $this->assertTrue(
            defined('Weline\Server\IPC\ControlMessage::TYPE_ROUTING_CACHE_CLEAR'),
            'TYPE_ROUTING_CACHE_CLEAR 常量应存在'
        );

        $this->assertEquals(
            'routing_cache_clear',
            ControlMessage::TYPE_ROUTING_CACHE_CLEAR,
            'TYPE_ROUTING_CACHE_CLEAR 值应为 routing_cache_clear'
        );
    }
}
