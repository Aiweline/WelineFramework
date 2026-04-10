<?php

declare(strict_types=1);

namespace Weline\Framework\test\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\LoggerFactory;
use Weline\Framework\Log\LoggerInterface;
use Weline\Framework\Log\FpmLogger;
use ReflectionMethod;

class LoggerFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::reset();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
    }

    /**
     * 测试创建默认日志器
     */
    public function testCreateDefault(): void
    {
        $logger = LoggerFactory::create();
        
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertEquals('app', $logger->getChannel());
    }

    /**
     * 测试创建指定通道的日志器
     */
    public function testCreateWithChannel(): void
    {
        $logger = LoggerFactory::create('custom');
        
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertEquals('custom', $logger->getChannel());
    }

    /**
     * 测试日志器缓存
     */
    public function testLoggerCaching(): void
    {
        $logger1 = LoggerFactory::create('test');
        $logger2 = LoggerFactory::create('test');
        
        $this->assertSame($logger1, $logger2);
    }

    /**
     * 测试不同通道返回不同实例
     */
    public function testDifferentChannels(): void
    {
        $logger1 = LoggerFactory::create('channel1');
        $logger2 = LoggerFactory::create('channel2');
        
        $this->assertNotSame($logger1, $logger2);
        $this->assertEquals('channel1', $logger1->getChannel());
        $this->assertEquals('channel2', $logger2->getChannel());
    }

    /**
     * 测试获取默认实例
     */
    public function testGetDefault(): void
    {
        $default = LoggerFactory::getDefault();
        
        $this->assertInstanceOf(LoggerInterface::class, $default);
        $this->assertEquals('app', $default->getChannel());
    }

    /**
     * 测试重置
     */
    public function testReset(): void
    {
        $logger1 = LoggerFactory::create('test');
        
        LoggerFactory::reset();
        
        $logger2 = LoggerFactory::create('test');
        
        $this->assertNotSame($logger1, $logger2);
    }

    /**
     * 测试 FPM 模式返回 FpmLogger
     */
    public function testFpmMode(): void
    {
        $logger = LoggerFactory::create();
        
        $this->assertInstanceOf(FpmLogger::class, $logger);
    }

    /**
     * 测试设置配置
     */
    public function testSetConfig(): void
    {
        LoggerFactory::setConfig([
            'min_level' => 'ERROR',
            'path' => 'var/log',
        ]);

        $logger = LoggerFactory::create();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testExceptionAndPhpErrorChannelsStayAtRootLevel(): void
    {
        LoggerFactory::setConfig(['path' => 'var/log']);
        $exceptionPath = $this->resolveLogPath('exception');
        $phpErrorPath = $this->resolveLogPath('php_error');

        $normalizedException = str_replace('\\', '/', rtrim($exceptionPath, '/\\'));
        $normalizedPhpError = str_replace('\\', '/', rtrim($phpErrorPath, '/\\'));

        $this->assertStringEndsWith('/var/log', $normalizedException);
        $this->assertStringEndsWith('/var/log', $normalizedPhpError);
    }

    public function testNonStandardLogFileChannelsGoToOtherDirectory(): void
    {
        LoggerFactory::setConfig(['path' => 'var/log']);
        $path = $this->resolveLogPath('ai_activity.log');
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));

        $this->assertStringEndsWith('/var/log/other', $normalized);
    }

    public function testWlsChannelUsesDedicatedDirectory(): void
    {
        LoggerFactory::setConfig(['path' => 'var/log']);
        $path = $this->resolveLogPath('wls');
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));

        $this->assertStringEndsWith('/var/log/wls', $normalized);
    }

    private function resolveLogPath(string $channel): string
    {
        $method = new ReflectionMethod(LoggerFactory::class, 'getLogPath');
        $method->setAccessible(true);
        return (string)$method->invoke(null, ['path' => 'var/log'], $channel);
    }
}
