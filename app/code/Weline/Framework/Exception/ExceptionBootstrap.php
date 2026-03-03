<?php

declare(strict_types=1);

/**
 * Weline Framework 异常引导器
 * 
 * 统一的异常/错误处理初始化入口
 * 在应用启动时调用一次，注册所有错误处理器
 */

namespace Weline\Framework\Exception;

use Weline\Framework\Exception\Handler\ErrorHandler;
use Weline\Framework\Exception\Handler\ExceptionHandler;
use Weline\Framework\Exception\Handler\ShutdownHandler;

class ExceptionBootstrap
{
    /**
     * 是否已初始化
     */
    private static bool $initialized = false;

    /**
     * 进程标签（用于日志标识）
     */
    private static string $processTag = 'FPM';

    /**
     * 进程上下文
     */
    private static array $context = [];

    /**
     * 初始化异常处理系统
     *
     * @param string $processTag 进程标签（如 'FPM', 'Worker#1', 'CLI'）
     * @param array $context 进程上下文信息
     */
    public static function init(string $processTag = 'FPM', array $context = []): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$processTag = $processTag;
        self::$context = $context;

        // 注册三层错误处理
        ErrorHandler::register();
        ExceptionHandler::register();
        ShutdownHandler::register();
    }

    /**
     * 获取进程标签
     */
    public static function getProcessTag(): string
    {
        return self::$processTag;
    }

    /**
     * 获取进程上下文
     */
    public static function getContext(): array
    {
        return self::$context;
    }

    /**
     * 设置进程上下文
     */
    public static function setContext(array $context): void
    {
        self::$context = $context;
    }

    /**
     * 添加上下文信息
     */
    public static function addContext(string $key, mixed $value): void
    {
        self::$context[$key] = $value;
    }

    /**
     * 重置状态（用于 WLS 状态管理）
     * 
     * 注意：这不会取消注册处理器，只重置内部状态
     * 处理器在进程生命周期内保持有效
     */
    public static function reset(): void
    {
        // 重置上下文，但不重置 initialized 标志
        // 因为处理器在进程生命周期内应该保持注册
        self::$context = [];
    }

    /**
     * 完全重置（用于测试）
     */
    public static function resetAll(): void
    {
        self::$initialized = false;
        self::$processTag = 'FPM';
        self::$context = [];
    }

    /**
     * 是否已初始化
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * 获取当前运行模式
     */
    public static function getRunMode(): string
    {
        if (defined('WELINE_SERVER_MODE') && WELINE_SERVER_MODE) {
            return 'WLS';
        }
        if (PHP_SAPI === 'cli') {
            return 'CLI';
        }
        return 'FPM';
    }

    /**
     * 是否为开发模式
     */
    public static function isDevMode(): bool
    {
        return defined('DEV') && DEV;
    }

    /**
     * 获取当前区域（用于渲染器选择）
     */
    public static function getArea(): string
    {
        // 检查是否为 API 请求
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        if (str_contains($uri, '/rest/') || str_contains($uri, '/api/')) {
            return 'api';
        }
        
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        
        // 检查后台区域
        if (defined('BACKEND_AREA') && BACKEND_AREA) {
            return 'backend';
        }
        
        return 'frontend';
    }
}
