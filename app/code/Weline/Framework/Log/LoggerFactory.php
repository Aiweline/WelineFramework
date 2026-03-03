<?php

declare(strict_types=1);

/**
 * Weline Framework 日志工厂
 * 
 * 自动选择合适的日志实现：
 * - WLS 模式：使用 WlsLogger（异步缓冲）
 * - FPM 模式：使用 FpmLogger（同步写入）
 */

namespace Weline\Framework\Log;

use Weline\Framework\Log\Handler\FileHandler;
use Weline\Framework\Log\Handler\RotatingFileHandler;

class LoggerFactory
{
    /**
     * 默认日志实例
     */
    private static ?LoggerInterface $defaultInstance = null;

    /**
     * 通道日志实例缓存
     * @var array<string, LoggerInterface>
     */
    private static array $channelInstances = [];

    /**
     * 日志配置
     */
    private static ?array $config = null;

    /**
     * 是否已注册 StateManager 重置
     */
    private static bool $stateManagerRegistered = false;

    /**
     * 创建或获取日志实例
     *
     * @param string|null $channel 通道名，null 使用默认通道
     * @return LoggerInterface
     */
    public static function create(?string $channel = null): LoggerInterface
    {
        // 注册到 StateManager（仅一次）
        self::registerStateManager();
        
        $channel = $channel ?? 'app';

        // 检查缓存
        if (isset(self::$channelInstances[$channel])) {
            return self::$channelInstances[$channel];
        }

        // 创建新实例
        $logger = self::createLogger($channel);
        self::$channelInstances[$channel] = $logger;

        // 如果是默认通道，也设置为默认实例
        if ($channel === 'app' && self::$defaultInstance === null) {
            self::$defaultInstance = $logger;
        }

        return $logger;
    }

    /**
     * 注册到 StateManager（WLS 模式下每个请求结束后自动重置）
     */
    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        
        if (class_exists('Weline\\Framework\\Runtime\\StateManager')) {
            \Weline\Framework\Runtime\StateManager::registerResetCallback(
                'LoggerFactory',
                [self::class, 'reset']
            );
            self::$stateManagerRegistered = true;
        }
    }

    /**
     * 获取默认日志实例
     */
    public static function getDefault(): LoggerInterface
    {
        if (self::$defaultInstance === null) {
            self::$defaultInstance = self::create('app');
        }
        return self::$defaultInstance;
    }

    /**
     * 创建日志实例
     */
    private static function createLogger(string $channel): LoggerInterface
    {
        $config = self::getConfig();
        
        // 判断运行模式
        if (self::isWlsMode()) {
            return self::createWlsLogger($channel, $config);
        }

        return self::createFpmLogger($channel, $config);
    }

    /**
     * 创建 FPM 日志器
     */
    private static function createFpmLogger(string $channel, array $config): FpmLogger
    {
        // 处理器
        $handler = self::createHandler($config);
        
        // 格式化器
        $formatter = new LogFormatter([
            'include_process_id' => $config['include_process_id'] ?? false,
            'include_memory' => $config['include_memory'] ?? false,
            'include_trace' => $config['include_trace'] ?? true,
        ]);
        
        // 过滤器
        $filter = LogFilter::getInstance();
        
        return new FpmLogger($channel, $handler, $formatter, $filter);
    }

    /**
     * 创建 WLS 日志器
     * 
     * 如果 WlsLogger 不存在，回退到 FpmLogger
     */
    private static function createWlsLogger(string $channel, array $config): LoggerInterface
    {
        // 尝试加载 WlsLogger
        $wlsLoggerClass = 'Weline\\Server\\Log\\WlsLogger';
        
        if (class_exists($wlsLoggerClass)) {
            // WlsLogger 存在，使用它（需要适配器包装以符合 LoggerInterface）
            return self::createWlsLoggerAdapter($channel, $config);
        }

        // 回退到 FPM 日志器
        return self::createFpmLogger($channel, $config);
    }

    /**
     * 创建 WlsLogger 适配器
     */
    private static function createWlsLoggerAdapter(string $channel, array $config): LoggerInterface
    {
        // 暂时回退到 FpmLogger，后续可以创建 WlsLoggerAdapter
        // TODO: 创建 WlsLoggerAdapter 以包装现有的 WlsLogger
        return self::createFpmLogger($channel, $config);
    }

    /**
     * 创建日志处理器
     */
    private static function createHandler(array $config): Handler\HandlerInterface
    {
        $logPath = self::getLogPath($config);
        $rotate = $config['rotate'] ?? [];
        
        // 如果配置了轮转，使用轮转处理器
        if (!empty($rotate) && ($rotate['strategy'] ?? 'none') !== 'none') {
            return new RotatingFileHandler($logPath, [
                'strategy' => $rotate['strategy'] ?? 'daily',
                'max_files' => $rotate['max_files'] ?? 7,
                'max_size' => $rotate['max_size'] ?? 52428800,
            ]);
        }
        
        return new FileHandler($logPath);
    }

    /**
     * 获取日志路径
     */
    private static function getLogPath(array $config): string
    {
        $path = $config['path'] ?? 'var/log';
        
        // 如果是相对路径，加上项目根目录
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Z]:/i', $path)) {
            if (defined('BP')) {
                $path = BP . $path;
            }
        }
        
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * 判断是否为 WLS 模式
     */
    private static function isWlsMode(): bool
    {
        return defined('WELINE_SERVER_MODE') && WELINE_SERVER_MODE === true;
    }

    /**
     * 获取日志配置
     */
    private static function getConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        try {
            if (class_exists('Weline\\Framework\\App\\Env')) {
                $env = \Weline\Framework\App\Env::getInstance();
                $config = $env->getConfig();
                self::$config = $config['log'] ?? [];
            } else {
                self::$config = [];
            }
        } catch (\Throwable) {
            self::$config = [];
        }

        return self::$config;
    }

    /**
     * 重置所有实例（用于 WLS 状态管理）
     */
    public static function reset(): void
    {
        // 关闭所有处理器
        foreach (self::$channelInstances as $logger) {
            if ($logger instanceof FpmLogger) {
                $logger->flush();
            }
        }
        
        self::$defaultInstance = null;
        self::$channelInstances = [];
        self::$config = null;
        
        // 同时重置过滤器
        LogFilter::reset();
    }

    /**
     * 刷新所有日志缓冲区
     */
    public static function flushAll(): void
    {
        foreach (self::$channelInstances as $logger) {
            if ($logger instanceof FpmLogger) {
                $logger->flush();
            }
        }
    }

    /**
     * 设置配置（用于测试）
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }
}
