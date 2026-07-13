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

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Log\Handler\FileHandler;
use Weline\Framework\Log\Handler\RotatingFileHandler;
use Weline\Framework\Manager\ObjectManager;

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
    private static bool $resolvingRuntime = false;
    private static bool $runtimeProviderResolved = false;
    private static ?RuntimeLoggerProviderInterface $runtimeProvider = null;

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
     * 运行模式由配置 log.runtime 提供默认值，再经事件 Weline_Framework_Log::resolve_runtime 解析（如 WLS 进程内改为 wls）。
     */
    private static function createLogger(string $channel): LoggerInterface
    {
        $config = self::getConfig();
        $runtime = self::resolveRuntime($config);

        $runtimeProvider = self::runtimeLoggerProvider();
        if ($runtimeProvider !== null && $runtimeProvider->supports($runtime, $config)) {
            return $runtimeProvider->create($channel, $config);
        }

        return self::createFpmLogger($channel, $config);
    }

    /**
     * 通过配置 + 事件解析当前日志运行模式（fpm | wls）
     * 不依赖常量，由配置与 Weline_Server 等观察者按环境改写。
     */
    private static function resolveRuntime(array $config): string
    {
        $runtime = $config['runtime'] ?? 'fpm';
        $data = ['runtime' => $runtime];

        if (self::$resolvingRuntime) {
            return $runtime === 'wls' ? 'wls' : 'fpm';
        }

        if (
            \class_exists(\Weline\Framework\Manager\ObjectManager::class, false)
            && \class_exists(\Weline\Framework\Event\EventsManager::class, false)
        ) {
            try {
                self::$resolvingRuntime = true;
                $eventsManager = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Event\EventsManager::class
                );
                $eventsManager->dispatch('Weline_Framework_Log::resolve_runtime', $data);
            } catch (\Throwable) {
                // 事件不可用时沿用配置默认
            } finally {
                self::$resolvingRuntime = false;
            }
        }

        $resolved = $data['runtime'] ?? $runtime;
        return $resolved === 'wls' ? 'wls' : 'fpm';
    }

    /**
     * 创建 FPM 日志器
     */
    private static function createFpmLogger(string $channel, array $config): FpmLogger
    {
        // 处理器（传递 channel 以支持子目录）
        $handler = self::createHandler($config, $channel);

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

    private static function runtimeLoggerProvider(): ?RuntimeLoggerProviderInterface
    {
        if (self::$runtimeProviderResolved) {
            return self::$runtimeProvider;
        }
        self::$runtimeProviderResolved = true;

        try {
            $implementation = (new ServiceProviderRegistry())->implementationFor(RuntimeLoggerProviderInterface::class);
            if ($implementation === null) {
                return null;
            }
            $provider = ObjectManager::getInstance($implementation);
            if ($provider instanceof RuntimeLoggerProviderInterface) {
                self::$runtimeProvider = $provider;
            }
        } catch (\Throwable) {
            self::$runtimeProvider = null;
        }

        return self::$runtimeProvider;
    }

    /**
     * 创建日志处理器
     */
    private static function createHandler(array $config, string $channel = 'app'): Handler\HandlerInterface
    {
        $logPath = self::getLogPath($config, $channel);
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
     * 获取日志路径（支持按通道分目录）
     *
     * 通道目录映射规则：
     * - app: var/log/app.log (默认)
     * - 规范通道（cron/sql/exception/wls 等）: var/log/{channel}/{channel}.log
     * - 其他非规范通道: var/log/other/{channel}.log（统一放入 other 目录）
     */
    private static function getLogPath(array $config, string $channel = 'app'): string
    {
        $basePath = $config['path'] ?? 'var/log';

        // 如果是相对路径，加上项目根目录
        if (!str_starts_with($basePath, '/') && !preg_match('/^[A-Z]:/i', $basePath)) {
            if (defined('BP')) {
                $basePath = BP . $basePath;
            }
        }

        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        // 规范通道列表（这些通道可以有独立目录）
        $globalRootChannels = [
            'exception',
            'php_error',
        ];
        $standardChannels = [
            'cron',
            'sql',
            'auth',
            'payment',
            'api',
            'wls',
            'session',
        ];

        // 默认通道直接写入 var/log/app.log
        if ($channel === 'app') {
            return $basePath . DIRECTORY_SEPARATOR;
        }

        // 规范通道：var/log/{channel}/
        if (in_array($channel, $globalRootChannels, true)) {
            return $basePath . DIRECTORY_SEPARATOR;
        }
        if (in_array($channel, $standardChannels, true)) {
            $channelPath = $basePath . DIRECTORY_SEPARATOR . $channel . DIRECTORY_SEPARATOR;

            // 确保目录存在
            if (!is_dir($channelPath)) {
                @mkdir($channelPath, 0755, true);
            }

            return $channelPath;
        }

        // 非规范通道：统一放入 var/log/other/{channel}.log
        $otherPath = $basePath . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR;

        // 确保 other 目录存在
        if (!is_dir($otherPath)) {
            @mkdir($otherPath, 0755, true);
        }

        return $otherPath;
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
        foreach (self::$channelInstances as $logger) {
            if (\method_exists($logger, 'flush')) {
                $logger->flush();
            }
        }

        self::$defaultInstance = null;
        self::$channelInstances = [];
        self::$config = null;

        LogFilter::reset();
    }

    /**
     * 刷新所有日志缓冲区
     */
    public static function flushAll(): void
    {
        foreach (self::$channelInstances as $logger) {
            if (\method_exists($logger, 'flush')) {
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
