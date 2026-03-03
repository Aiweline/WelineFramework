<?php

declare(strict_types=1);

namespace Weline\Framework\test\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\LogFilter;
use Weline\Framework\Log\LogLevel;

class LogFilterTest extends TestCase
{
    /**
     * 测试默认级别过滤
     */
    public function testDefaultLevelFiltering(): void
    {
        $filter = new LogFilter([
            'min_level' => 'WARNING',
        ]);

        $this->assertTrue($filter->shouldLog(LogLevel::ERROR, 'test'));
        $this->assertTrue($filter->shouldLog(LogLevel::WARNING, 'test'));
        $this->assertFalse($filter->shouldLog(LogLevel::INFO, 'test'));
        $this->assertFalse($filter->shouldLog(LogLevel::DEBUG, 'test'));
    }

    /**
     * 测试通道级别覆盖
     */
    public function testChannelLevelOverride(): void
    {
        $filter = new LogFilter([
            'min_level' => 'WARNING',
            'channels' => [
                'debug_channel' => [
                    'min_level' => 'DEBUG',
                ],
            ],
        ]);

        // 默认通道使用 WARNING 级别
        $this->assertFalse($filter->shouldLog(LogLevel::DEBUG, 'default'));
        
        // debug_channel 使用 DEBUG 级别
        $this->assertTrue($filter->shouldLog(LogLevel::DEBUG, 'debug_channel'));
    }

    /**
     * 测试禁用通道
     */
    public function testDisabledChannel(): void
    {
        $filter = new LogFilter([
            'min_level' => 'DEBUG',
            'channels' => [
                'disabled_channel' => [
                    'enabled' => false,
                ],
            ],
        ]);

        // 正常通道可以记录
        $this->assertTrue($filter->shouldLog(LogLevel::ERROR, 'normal'));
        
        // 禁用的通道不能记录
        $this->assertFalse($filter->shouldLog(LogLevel::ERROR, 'disabled_channel'));
    }

    /**
     * 测试禁用/启用通道
     */
    public function testDisableEnableChannel(): void
    {
        $filter = new LogFilter(['min_level' => 'DEBUG']);
        
        $this->assertTrue($filter->shouldLog(LogLevel::INFO, 'test'));
        
        $filter->disableChannel('test');
        $this->assertFalse($filter->shouldLog(LogLevel::INFO, 'test'));
        
        $filter->enableChannel('test');
        $this->assertTrue($filter->shouldLog(LogLevel::INFO, 'test'));
    }

    /**
     * 测试设置通道级别
     */
    public function testSetChannelLevel(): void
    {
        $filter = new LogFilter(['min_level' => 'DEBUG']);
        
        $this->assertTrue($filter->shouldLog(LogLevel::DEBUG, 'test'));
        
        $filter->setChannelLevel('test', LogLevel::ERROR);
        $this->assertFalse($filter->shouldLog(LogLevel::DEBUG, 'test'));
        $this->assertTrue($filter->shouldLog(LogLevel::ERROR, 'test'));
    }

    /**
     * 测试单例重置
     */
    public function testSingletonReset(): void
    {
        LogFilter::reset();
        
        $instance1 = LogFilter::getInstance();
        $instance2 = LogFilter::getInstance();
        
        $this->assertSame($instance1, $instance2);
        
        LogFilter::reset();
        
        $instance3 = LogFilter::getInstance();
        $this->assertNotSame($instance1, $instance3);
    }
}
