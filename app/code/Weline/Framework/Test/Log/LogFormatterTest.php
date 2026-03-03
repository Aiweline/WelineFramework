<?php

declare(strict_types=1);

namespace Weline\Framework\test\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\LogFormatter;
use Weline\Framework\Log\LogLevel;

class LogFormatterTest extends TestCase
{
    private LogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LogFormatter();
    }

    /**
     * 测试紧凑格式输出
     */
    public function testCompactFormat(): void
    {
        $output = $this->formatter->format(
            LogLevel::INFO,
            'Test message',
            [],
            'test',
            true
        );

        $this->assertStringContainsString('[INFO]', $output);
        $this->assertStringContainsString('test', $output);
        $this->assertStringContainsString('Test message', $output);
        $this->assertStringEndsWith(PHP_EOL, $output);
    }

    /**
     * 测试详细格式输出
     */
    public function testVerboseFormat(): void
    {
        $output = $this->formatter->format(
            LogLevel::ERROR,
            'Error occurred',
            [],
            'app',
            false
        );

        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString('[app]', $output);
        $this->assertStringContainsString('Error occurred', $output);
        $this->assertStringContainsString('===', $output);
    }

    /**
     * 测试上下文插值
     */
    public function testContextInterpolation(): void
    {
        $output = $this->formatter->format(
            LogLevel::INFO,
            'User {user} logged in from {ip}',
            ['user' => 'john', 'ip' => '192.168.1.1'],
            'auth',
            true
        );

        $this->assertStringContainsString('User john logged in from 192.168.1.1', $output);
    }

    /**
     * 测试数组上下文
     */
    public function testArrayContext(): void
    {
        $output = $this->formatter->format(
            LogLevel::DEBUG,
            'Data: {data}',
            ['data' => ['a' => 1, 'b' => 2]],
            'test',
            true
        );

        $this->assertStringContainsString('Data:', $output);
    }

    /**
     * 测试进程 ID 选项
     */
    public function testWithProcessId(): void
    {
        $formatterWithPid = $this->formatter->withProcessId(true);
        
        $output = $formatterWithPid->format(
            LogLevel::INFO,
            'Test',
            [],
            'test',
            true
        );

        $this->assertStringContainsString('[pid:', $output);
    }

    /**
     * 测试内存选项
     */
    public function testWithMemory(): void
    {
        $formatterWithMemory = $this->formatter->withMemory(true);
        
        $output = $formatterWithMemory->format(
            LogLevel::INFO,
            'Test',
            [],
            'test',
            true
        );

        $this->assertStringContainsString('[mem:', $output);
    }
}
