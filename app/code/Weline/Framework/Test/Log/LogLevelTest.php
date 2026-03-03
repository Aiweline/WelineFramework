<?php

declare(strict_types=1);

namespace Weline\Framework\test\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\LogLevel;

class LogLevelTest extends TestCase
{
    /**
     * 测试级别值
     */
    public function testLevelValues(): void
    {
        $this->assertEquals(800, LogLevel::EMERGENCY->value);
        $this->assertEquals(700, LogLevel::ALERT->value);
        $this->assertEquals(600, LogLevel::CRITICAL->value);
        $this->assertEquals(500, LogLevel::ERROR->value);
        $this->assertEquals(400, LogLevel::WARNING->value);
        $this->assertEquals(300, LogLevel::NOTICE->value);
        $this->assertEquals(200, LogLevel::INFO->value);
        $this->assertEquals(100, LogLevel::DEBUG->value);
    }

    /**
     * 测试 shouldLog 方法
     */
    public function testShouldLog(): void
    {
        // ERROR 级别应该记录 ERROR 及以上
        $this->assertTrue(LogLevel::ERROR->shouldLog(LogLevel::ERROR));
        $this->assertTrue(LogLevel::CRITICAL->shouldLog(LogLevel::ERROR));
        $this->assertTrue(LogLevel::EMERGENCY->shouldLog(LogLevel::ERROR));
        
        // WARNING 不应该被 ERROR 最小级别记录
        $this->assertFalse(LogLevel::WARNING->shouldLog(LogLevel::ERROR));
        $this->assertFalse(LogLevel::INFO->shouldLog(LogLevel::ERROR));
        $this->assertFalse(LogLevel::DEBUG->shouldLog(LogLevel::ERROR));
    }

    /**
     * 测试从字符串解析
     */
    public function testFromString(): void
    {
        $this->assertEquals(LogLevel::ERROR, LogLevel::fromString('error'));
        $this->assertEquals(LogLevel::ERROR, LogLevel::fromString('ERROR'));
        $this->assertEquals(LogLevel::WARNING, LogLevel::fromString('warning'));
        $this->assertEquals(LogLevel::WARNING, LogLevel::fromString('WARN'));
        $this->assertEquals(LogLevel::DEBUG, LogLevel::fromString('debug'));
    }

    /**
     * 测试无效字符串抛出异常
     */
    public function testFromStringInvalid(): void
    {
        $this->expectException(\ValueError::class);
        LogLevel::fromString('invalid');
    }

    /**
     * 测试安全解析
     */
    public function testTryFromString(): void
    {
        $this->assertEquals(LogLevel::ERROR, LogLevel::tryFromString('error'));
        $this->assertEquals(LogLevel::INFO, LogLevel::tryFromString('invalid'));
        $this->assertEquals(LogLevel::WARNING, LogLevel::tryFromString('invalid', LogLevel::WARNING));
    }

    /**
     * 测试小写名称
     */
    public function testToLowerCase(): void
    {
        $this->assertEquals('error', LogLevel::ERROR->toLowerCase());
        $this->assertEquals('debug', LogLevel::DEBUG->toLowerCase());
    }

    /**
     * 测试获取所有名称
     */
    public function testNames(): void
    {
        $names = LogLevel::names();
        $this->assertContains('EMERGENCY', $names);
        $this->assertContains('ERROR', $names);
        $this->assertContains('DEBUG', $names);
        $this->assertCount(8, $names);
    }
}
