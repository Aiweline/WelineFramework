<?php
declare(strict_types=1);

/**
 * Weline Framework - 运行时辅助类
 * 
 * 提供静态方法检测当前运行模式，避免直接使用 WLS_MODE 常量。
 * 这是一个纯粹的辅助类，不持有状态。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

/**
 * 运行时辅助类
 * 
 * 用法：
 * - Runtime::isPersistent() 替代 defined('WLS_MODE') && WLS_MODE
 * - Runtime::isWls() 检测 WLS 模式
 * - Runtime::isFpm() 检测 FPM 模式
 * - Runtime::isCli() 检测 CLI 模式
 */
class Runtime
{
    /**
     * 当前运行模式（缓存，避免重复检测）
     */
    private static ?string $mode = null;
    
    /**
     * 检测是否为常驻内存模式
     * 
     * 替代 defined('WLS_MODE') && WLS_MODE 判断
     * 
     * @return bool
     */
    public static function isPersistent(): bool
    {
        return self::isWls();
    }
    
    /**
     * 检测是否为 WLS（Weline Server）模式
     * 
     * @return bool
     */
    public static function isWls(): bool
    {
        if (self::$mode !== null) {
            return self::$mode === RuntimeInterface::MODE_WLS;
        }
        
        // 通过 WLS_MODE 常量检测
        if (\defined('WLS_MODE') && WLS_MODE) {
            self::$mode = RuntimeInterface::MODE_WLS;
            return true;
        }
        
        self::$mode = self::detectMode();
        return self::$mode === RuntimeInterface::MODE_WLS;
    }
    
    /**
     * 检测是否为 FPM 模式
     * 
     * @return bool
     */
    public static function isFpm(): bool
    {
        if (self::$mode === null) {
            self::$mode = self::detectMode();
        }
        return self::$mode === RuntimeInterface::MODE_FPM;
    }
    
    /**
     * 检测是否为 CLI 模式
     * 
     * @return bool
     */
    public static function isCli(): bool
    {
        return \in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && !self::isWls();
    }
    
    /**
     * 获取当前运行模式
     * 
     * @return string RuntimeInterface::MODE_*
     */
    public static function getMode(): string
    {
        if (self::$mode === null) {
            self::$mode = self::detectMode();
        }
        return self::$mode;
    }
    
    /**
     * 检测当前运行模式
     * 
     * @return string
     */
    private static function detectMode(): string
    {
        // WLS 模式
        if (\defined('WLS_MODE') && WLS_MODE) {
            return RuntimeInterface::MODE_WLS;
        }
        
        // CLI 模式
        if (\in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return RuntimeInterface::MODE_CLI;
        }
        
        // FPM 模式
        return RuntimeInterface::MODE_FPM;
    }
    
    /**
     * 重置模式检测缓存（用于测试）
     */
    public static function resetModeCache(): void
    {
        self::$mode = null;
    }
    
    /**
     * 设置模式（用于测试或强制模式）
     * 
     * @param string $mode RuntimeInterface::MODE_*
     */
    public static function setMode(string $mode): void
    {
        self::$mode = $mode;
    }
}
