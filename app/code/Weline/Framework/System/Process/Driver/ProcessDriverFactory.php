<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

/**
 * 进程驱动工厂
 * 
 * 遵循开闭原则（OCP）：
 * - 添加新系统支持只需创建新驱动并注册到 $driverClasses
 * - 无需修改现有驱动代码
 * 
 * 遵循里氏替换原则（LSP）：
 * - 所有驱动实现相同接口，可互相替换
 */
class ProcessDriverFactory
{
    /**
     * 缓存的驱动实例
     */
    private static ?ProcessDriverInterface $driver = null;
    
    /**
     * 已注册的驱动类列表
     * 
     * 按优先级排序，先匹配先使用
     * 
     * @var class-string<ProcessDriverInterface>[]
     */
    private static array $driverClasses = [
        WindowsProcessDriver::class,
        LinuxProcessDriver::class,
    ];
    
    /**
     * 获取当前系统对应的驱动实例
     * 
     * @return ProcessDriverInterface
     * @throws \RuntimeException 如果没有支持当前系统的驱动
     */
    public static function getDriver(): ProcessDriverInterface
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        
        foreach (self::$driverClasses as $driverClass) {
            /** @var ProcessDriverInterface $driver */
            $driver = new $driverClass();
            if ($driver->supports()) {
                self::$driver = $driver;
                return $driver;
            }
        }
        
        throw new \RuntimeException(
            \sprintf(
                '没有找到支持当前操作系统 (%s) 的进程驱动。可用驱动: %s',
                PHP_OS,
                \implode(', ', self::$driverClasses)
            )
        );
    }
    
    /**
     * 注册自定义驱动类
     * 
     * 用于扩展支持新的操作系统（如 FreeBSD, OpenBSD 等）
     * 
     * @param class-string<ProcessDriverInterface> $driverClass 驱动类名
     * @param bool $prepend 是否插入到列表开头（优先匹配）
     */
    public static function registerDriver(string $driverClass, bool $prepend = false): void
    {
        if (!\in_array($driverClass, self::$driverClasses, true)) {
            if ($prepend) {
                \array_unshift(self::$driverClasses, $driverClass);
            } else {
                self::$driverClasses[] = $driverClass;
            }
        }
        
        // 清除缓存，下次重新检测
        self::$driver = null;
    }
    
    /**
     * 获取所有已注册的驱动类
     * 
     * @return class-string<ProcessDriverInterface>[]
     */
    public static function getRegisteredDrivers(): array
    {
        return self::$driverClasses;
    }
    
    /**
     * 重置驱动缓存（主要用于测试）
     */
    public static function reset(): void
    {
        self::$driver = null;
    }
    
    /**
     * 检查当前系统是否为 Windows
     * 
     * @return bool
     */
    public static function isWindows(): bool
    {
        return \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    /**
     * 检查当前系统是否为 Linux
     * 
     * @return bool
     */
    public static function isLinux(): bool
    {
        return \strtoupper(PHP_OS) === 'LINUX';
    }
    
    /**
     * 检查当前系统是否为 macOS
     * 
     * @return bool
     */
    public static function isMacOS(): bool
    {
        return \strtoupper(PHP_OS) === 'DARWIN';
    }
}
