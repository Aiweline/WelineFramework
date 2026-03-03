<?php

declare(strict_types=1);

/**
 * Weline Framework 日志过滤器
 * 
 * 根据配置过滤日志：
 * - 全局最小级别
 * - 通道级别
 * - 模块级别
 */

namespace Weline\Framework\Log;

use Weline\Framework\App\Env;

class LogFilter
{
    /**
     * 全局最小日志级别
     */
    private LogLevel $globalMinLevel;

    /**
     * 通道级别覆盖
     * @var array<string, LogLevel>
     */
    private array $channelLevels = [];

    /**
     * 模块级别覆盖
     * @var array<string, LogLevel>
     */
    private array $moduleLevels = [];

    /**
     * 禁用的通道
     * @var array<string, bool>
     */
    private array $disabledChannels = [];

    /**
     * 单例实例
     */
    private static ?self $instance = null;

    public function __construct(?array $config = null)
    {
        $this->loadConfig($config);
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 重置单例（用于 WLS 状态管理）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 加载配置
     */
    private function loadConfig(?array $config = null): void
    {
        if ($config === null) {
            $config = $this->getLogConfig();
        }

        // 全局最小级别
        $minLevel = $config['min_level'] ?? 'INFO';
        $this->globalMinLevel = LogLevel::tryFromString($minLevel, LogLevel::INFO);

        // 通道级别
        $channels = $config['channels'] ?? [];
        foreach ($channels as $channel => $channelConfig) {
            if (is_array($channelConfig)) {
                if (isset($channelConfig['enabled']) && !$channelConfig['enabled']) {
                    $this->disabledChannels[$channel] = true;
                }
                if (isset($channelConfig['min_level'])) {
                    $this->channelLevels[$channel] = LogLevel::tryFromString(
                        $channelConfig['min_level'],
                        $this->globalMinLevel
                    );
                }
            }
        }

        // 模块级别
        $moduleLevels = $config['module_levels'] ?? [];
        foreach ($moduleLevels as $module => $level) {
            $this->moduleLevels[$module] = LogLevel::tryFromString($level, $this->globalMinLevel);
        }
    }

    /**
     * 获取日志配置
     */
    private function getLogConfig(): array
    {
        try {
            $env = Env::getInstance();
            $config = $env->getConfig();
            return $config['log'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 判断是否应该记录此日志
     *
     * @param LogLevel $level 日志级别
     * @param string $channel 通道名
     * @param array $context 上下文（可能包含模块信息）
     * @return bool
     */
    public function shouldLog(LogLevel $level, string $channel, array $context = []): bool
    {
        // 检查通道是否禁用
        if (isset($this->disabledChannels[$channel])) {
            return false;
        }

        // 确定最小级别（优先级：通道 > 模块 > 全局）
        $minLevel = $this->getMinLevelForChannel($channel, $context);

        return $level->shouldLog($minLevel);
    }

    /**
     * 获取通道的最小级别
     */
    private function getMinLevelForChannel(string $channel, array $context): LogLevel
    {
        // 通道级别覆盖
        if (isset($this->channelLevels[$channel])) {
            return $this->channelLevels[$channel];
        }

        // 尝试从通道名或上下文提取模块名
        $module = $context['_module'] ?? $this->extractModuleFromChannel($channel);
        
        if ($module !== null && isset($this->moduleLevels[$module])) {
            return $this->moduleLevels[$module];
        }

        return $this->globalMinLevel;
    }

    /**
     * 从通道名提取模块名
     * 
     * 例如：weline_framework -> Weline_Framework
     */
    private function extractModuleFromChannel(string $channel): ?string
    {
        // 尝试匹配 vendor_module 格式
        if (preg_match('/^([a-z]+)_([a-z]+)/i', $channel, $matches)) {
            return ucfirst($matches[1]) . '_' . ucfirst($matches[2]);
        }
        return null;
    }

    /**
     * 获取全局最小级别
     */
    public function getGlobalMinLevel(): LogLevel
    {
        return $this->globalMinLevel;
    }

    /**
     * 设置全局最小级别
     */
    public function setGlobalMinLevel(LogLevel $level): self
    {
        $this->globalMinLevel = $level;
        return $this;
    }

    /**
     * 设置通道级别
     */
    public function setChannelLevel(string $channel, LogLevel $level): self
    {
        $this->channelLevels[$channel] = $level;
        return $this;
    }

    /**
     * 禁用通道
     */
    public function disableChannel(string $channel): self
    {
        $this->disabledChannels[$channel] = true;
        return $this;
    }

    /**
     * 启用通道
     */
    public function enableChannel(string $channel): self
    {
        unset($this->disabledChannels[$channel]);
        return $this;
    }

    /**
     * 检查是否为开发模式
     */
    public function isDevMode(): bool
    {
        return defined('DEV') && DEV;
    }
}
