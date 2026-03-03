<?php
declare(strict_types=1);

namespace Weline\Server\Test;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\LogLevel;
use Weline\Server\Log\LogConfig;
use Weline\Server\Log\WlsLogger;

/**
 * WlsLogger 单元测试
 */
class WlsLoggerTest extends TestCase
{
    private string $testLogDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testLogDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-test-' . \getmypid() . DIRECTORY_SEPARATOR;
        if (!\is_dir($this->testLogDir)) {
            \mkdir($this->testLogDir, 0755, true);
        }
        WlsLogger::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        WlsLogger::reset();
        
        // 清理测试日志文件
        if (\is_dir($this->testLogDir)) {
            $files = \glob($this->testLogDir . '*');
            foreach ($files as $file) {
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
            @\rmdir($this->testLogDir);
        }
    }

    public function testSingletonInstance(): void
    {
        $logger1 = WlsLogger::getInstance();
        $logger2 = WlsLogger::getInstance();
        
        $this->assertSame($logger1, $logger2, 'WlsLogger should be singleton');
    }

    public function testSetProcessTag(): void
    {
        $logger = WlsLogger::getInstance();
        $logger->setProcessTag('TestWorker#1');
        
        $this->assertEquals('TestWorker#1', $logger->getProcessTag());
    }

    public function testLogLevelComparison(): void
    {
        $this->assertTrue(LogLevel::isAtLeast(LogLevel::ERROR, LogLevel::WARNING));
        $this->assertTrue(LogLevel::isAtLeast(LogLevel::ERROR, LogLevel::ERROR));
        $this->assertFalse(LogLevel::isAtLeast(LogLevel::WARNING, LogLevel::ERROR));
        
        $this->assertEquals(0, LogLevel::compare(LogLevel::INFO, LogLevel::INFO));
        $this->assertEquals(1, LogLevel::compare(LogLevel::ERROR, LogLevel::INFO));
        $this->assertEquals(-1, LogLevel::compare(LogLevel::INFO, LogLevel::ERROR));
    }

    public function testLogLevelNormalization(): void
    {
        $this->assertEquals(LogLevel::INFO, LogLevel::normalize('info'));
        $this->assertEquals(LogLevel::INFO, LogLevel::normalize('INFO'));
        $this->assertEquals(LogLevel::ERROR, LogLevel::normalize('error'));
        $this->assertEquals(LogLevel::INFO, LogLevel::normalize('unknown'));
    }

    public function testLogLevelColors(): void
    {
        $this->assertNotEmpty(LogLevel::getColor(LogLevel::ERROR));
        $this->assertNotEmpty(LogLevel::getColor(LogLevel::WARNING));
        $this->assertNotEmpty(LogLevel::getReset());
    }

    public function testAllLogLevels(): void
    {
        $levels = LogLevel::all();
        
        $this->assertContains(LogLevel::DEBUG, $levels);
        $this->assertContains(LogLevel::INFO, $levels);
        $this->assertContains(LogLevel::NOTICE, $levels);
        $this->assertContains(LogLevel::WARNING, $levels);
        $this->assertContains(LogLevel::ERROR, $levels);
        $this->assertContains(LogLevel::FATAL, $levels);
    }

    public function testLogConfigDefaults(): void
    {
        LogConfig::clearCache();
        
        $config = LogConfig::get();
        
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('path', $config);
        $this->assertArrayHasKey('level', $config);
    }

    public function testLogConfigPaths(): void
    {
        $logDir = LogConfig::getLogDir();
        $this->assertNotEmpty($logDir);
        
        $mainLog = LogConfig::getMainLogFile();
        $this->assertStringContainsString('wls.log', $mainLog);
        
        $errorLog = LogConfig::getErrorLogFile();
        $this->assertStringContainsString('error.log', $errorLog);
        
        $crashLog = LogConfig::getCrashLogFile();
        $this->assertStringContainsString('crash.log', $crashLog);
    }

    public function testLoggerChainableConfiguration(): void
    {
        $logger = WlsLogger::getInstance()
            ->setProcessTag('TestProcess')
            ->setMinLevel(LogLevel::WARNING)
            ->setStdoutEnabled(false)
            ->setFileEnabled(true);
        
        $this->assertInstanceOf(WlsLogger::class, $logger);
        $this->assertEquals('TestProcess', $logger->getProcessTag());
    }
}
