<?php

declare(strict_types=1);

namespace Weline\Framework\test\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\LoggerFactory;
use Weline\Framework\Log\LogLevel;
use Weline\Framework\Log\Handler\FileHandler;

/**
 * 日志系统集成测试
 */
class IntegrationTest extends TestCase
{
    private string $testLogDir;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_log_test_' . uniqid();
        mkdir($this->testLogDir, 0755, true);
        
        LoggerFactory::reset();
        LoggerFactory::setConfig([
            'path' => $this->testLogDir,
            'min_level' => 'DEBUG',
        ]);
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        
        // 清理测试目录
        $this->removeDirectory($this->testLogDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * 测试完整的日志写入流程
     */
    public function testFullLoggingFlow(): void
    {
        $logger = LoggerFactory::create('integration_test');
        
        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        
        $logger->flush();
        
        $logFile = $this->testLogDir . DIRECTORY_SEPARATOR . 'integration_test.log';
        $this->assertFileExists($logFile);
        
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Error message', $content);
    }

    /**
     * 测试上下文插值
     */
    public function testContextInterpolation(): void
    {
        $logger = LoggerFactory::create('context_test');
        
        $logger->info('User {user} performed {action}', [
            'user' => 'john',
            'action' => 'login',
        ]);
        
        $logger->flush();
        
        $logFile = $this->testLogDir . DIRECTORY_SEPARATOR . 'context_test.log';
        $content = file_get_contents($logFile);
        
        $this->assertStringContainsString('User john performed login', $content);
    }

    /**
     * 测试通道切换
     */
    public function testChannelSwitching(): void
    {
        $logger = LoggerFactory::create('channel1');
        $logger->info('Message in channel1');
        
        $logger2 = $logger->withChannel('channel2');
        $logger2->info('Message in channel2');
        
        $this->assertEquals('channel1', $logger->getChannel());
        $this->assertEquals('channel2', $logger2->getChannel());
    }

    /**
     * 测试级别过滤
     */
    public function testLevelFiltering(): void
    {
        LoggerFactory::reset();
        LoggerFactory::setConfig([
            'path' => $this->testLogDir,
            'min_level' => 'WARNING',
        ]);
        
        $logger = LoggerFactory::create('filter_test');
        
        $logger->debug('Debug - should not appear');
        $logger->info('Info - should not appear');
        $logger->warning('Warning - should appear');
        $logger->error('Error - should appear');
        
        $logger->flush();
        
        $logFile = $this->testLogDir . DIRECTORY_SEPARATOR . 'filter_test.log';
        
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $this->assertStringNotContainsString('Debug - should not appear', $content);
            $this->assertStringNotContainsString('Info - should not appear', $content);
            $this->assertStringContainsString('Warning - should appear', $content);
            $this->assertStringContainsString('Error - should appear', $content);
        }
    }

    /**
     * 测试异常日志
     */
    public function testExceptionLogging(): void
    {
        if (!function_exists('w_log_exception')) {
            $this->markTestSkipped('w_log_exception function not loaded');
        }
        
        $exception = new \RuntimeException('Test exception', 500);
        w_log_exception($exception, 'Exception test', 'exception_test');
        
        LoggerFactory::flushAll();
    }

    /**
     * 测试多通道并发写入
     */
    public function testMultipleChannels(): void
    {
        $channels = ['app', 'auth', 'payment', 'api'];
        $loggers = [];
        
        foreach ($channels as $channel) {
            $loggers[$channel] = LoggerFactory::create($channel);
            $loggers[$channel]->info("Message for {$channel}");
        }
        
        LoggerFactory::flushAll();
        
        foreach ($channels as $channel) {
            $logFile = $this->testLogDir . DIRECTORY_SEPARATOR . "{$channel}.log";
            $this->assertFileExists($logFile);
            
            $content = file_get_contents($logFile);
            $this->assertStringContainsString("Message for {$channel}", $content);
        }
    }
}
