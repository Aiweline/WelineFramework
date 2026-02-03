<?php
declare(strict_types=1);

/**
 * Weline Server - 事件循环工厂
 * 
 * 自动选择最优的事件循环实现，支持优雅降级
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Event;

/**
 * EventFactory - 事件循环工厂
 * 
 * 优先级（从高到低）：
 * 1. Event 扩展（libevent）- 最高性能
 * 2. Select（stream_select）- 纯 PHP 兼容
 */
class EventFactory
{
    /**
     * 事件循环驱动列表（按优先级排序）
     */
    protected static array $drivers = [
        'event' => [
            'class' => Event::class,
            'name' => 'Event 扩展',
            'check' => 'EventBase',
            'performance' => '30,000-50,000 QPS',
            'install' => 'pecl install event',
            'description' => '基于 libevent 的高性能事件循环',
        ],
        'select' => [
            'class' => Select::class,
            'name' => 'stream_select',
            'check' => null, // 始终可用
            'performance' => '8,000-15,000 QPS',
            'install' => null,
            'description' => '纯 PHP 实现，无需扩展',
        ],
    ];
    
    /**
     * 当前使用的驱动名称
     */
    protected static ?string $currentDriver = null;
    
    /**
     * 创建事件循环实例
     * 
     * @return EventInterface
     */
    public static function create(): EventInterface
    {
        $driver = self::detectBestDriver();
        self::$currentDriver = $driver;
        
        $driverInfo = self::$drivers[$driver];
        $class = $driverInfo['class'];
        
        return new $class();
    }
    
    /**
     * 检测最佳事件循环驱动
     */
    public static function detectBestDriver(): string
    {
        foreach (self::$drivers as $name => $driver) {
            if (self::isDriverAvailable($name)) {
                return $name;
            }
        }
        
        // 默认回退到 select
        return 'select';
    }
    
    /**
     * 检查驱动是否可用
     */
    public static function isDriverAvailable(string $driver): bool
    {
        if (!isset(self::$drivers[$driver])) {
            return false;
        }
        
        $check = self::$drivers[$driver]['check'];
        
        // 无需检查的驱动（如 select）始终可用
        if ($check === null) {
            return true;
        }
        
        // 检查类是否存在
        return \class_exists($check);
    }
    
    /**
     * 获取当前使用的驱动名称
     */
    public static function getCurrentDriver(): ?string
    {
        return self::$currentDriver;
    }
    
    /**
     * 获取当前驱动信息
     */
    public static function getCurrentDriverInfo(): ?array
    {
        if (self::$currentDriver === null) {
            return null;
        }
        
        return self::$drivers[self::$currentDriver] ?? null;
    }
    
    /**
     * 获取所有驱动信息
     */
    public static function getAllDrivers(): array
    {
        $result = [];
        
        foreach (self::$drivers as $name => $driver) {
            $result[$name] = \array_merge($driver, [
                'available' => self::isDriverAvailable($name),
            ]);
        }
        
        return $result;
    }
    
    /**
     * 获取最佳驱动名称
     */
    public static function getBestDriverName(): string
    {
        return self::detectBestDriver();
    }
    
    /**
     * 获取性能诊断信息
     */
    public static function getDiagnostics(): array
    {
        $diagnostics = [
            'current_driver' => self::$currentDriver,
            'best_driver' => self::detectBestDriver(),
            'is_optimal' => false,
            'drivers' => [],
            'recommendations' => [],
        ];
        
        $bestDriver = 'event'; // 最优驱动
        $currentDriver = self::$currentDriver ?? self::detectBestDriver();
        
        // 检查是否使用最优驱动
        $diagnostics['is_optimal'] = ($currentDriver === $bestDriver);
        
        // 收集驱动信息
        foreach (self::$drivers as $name => $driver) {
            $available = self::isDriverAvailable($name);
            $diagnostics['drivers'][$name] = [
                'name' => $driver['name'],
                'available' => $available,
                'performance' => $driver['performance'],
                'description' => $driver['description'],
            ];
            
            // 如果最优驱动不可用，添加安装建议
            if ($name === $bestDriver && !$available && $driver['install']) {
                $diagnostics['recommendations'][] = [
                    'level' => 'high',
                    'driver' => $name,
                    'message' => \__('安装 %{1} 可提升性能至 %{2}', [$driver['name'], $driver['performance']]),
                    'action' => $driver['install'],
                ];
            }
        }
        
        return $diagnostics;
    }
    
    /**
     * 获取缺失的最优驱动列表
     */
    public static function getMissingOptimalDrivers(): array
    {
        $missing = [];
        
        // 只检查最优驱动（event）
        if (!self::isDriverAvailable('event')) {
            $driver = self::$drivers['event'];
            $missing['event'] = [
                'name' => $driver['name'],
                'performance' => $driver['performance'],
                'install' => $driver['install'],
                'benefit' => \__('性能提升 100-200%%，支持更多并发连接'),
            ];
        }
        
        return $missing;
    }
    
    /**
     * 检查是否使用最优配置
     */
    public static function isOptimalConfiguration(): bool
    {
        return self::isDriverAvailable('event');
    }
    
    /**
     * 重置当前驱动
     */
    public static function reset(): void
    {
        self::$currentDriver = null;
    }
}
