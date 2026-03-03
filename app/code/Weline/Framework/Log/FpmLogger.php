<?php

declare(strict_types=1);

/**
 * Weline Framework FPM 日志器
 * 
 * 适用于传统 PHP-FPM 模式的同步日志实现
 */

namespace Weline\Framework\Log;

use Weline\Framework\Log\Handler\FileHandler;
use Weline\Framework\Log\Handler\HandlerInterface;

class FpmLogger implements LoggerInterface
{
    /**
     * 通道名
     */
    private string $channel;

    /**
     * 日志处理器
     */
    private HandlerInterface $handler;

    /**
     * 日志格式化器
     */
    private LogFormatter $formatter;

    /**
     * 日志过滤器
     */
    private LogFilter $filter;

    /**
     * 是否使用紧凑格式
     */
    private bool $compact = true;

    public function __construct(
        ?string $channel = null,
        ?HandlerInterface $handler = null,
        ?LogFormatter $formatter = null,
        ?LogFilter $filter = null
    ) {
        $this->channel = $channel ?? 'app';
        $this->handler = $handler ?? new FileHandler();
        $this->formatter = $formatter ?? new LogFormatter();
        $this->filter = $filter ?? LogFilter::getInstance();
    }

    /**
     * 记录日志
     */
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            $logLevel = LogLevel::fromString($level);
        } catch (\ValueError) {
            $logLevel = LogLevel::INFO;
        }

        $this->logWithLevel($logLevel, $message, $context);
    }

    /**
     * 使用 LogLevel 枚举记录日志
     */
    private function logWithLevel(LogLevel $level, string $message, array $context): void
    {
        // 级别过滤
        if (!$this->filter->shouldLog($level, $this->channel, $context)) {
            return;
        }

        // 格式化
        $formatted = $this->formatter->format(
            $level,
            $message,
            $context,
            $this->channel,
            $this->compact
        );

        // 写入
        $this->handler->write($level, $formatted, $this->channel);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logWithLevel(LogLevel::DEBUG, $message, $context);
    }

    /**
     * 获取当前通道
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * 创建一个指定通道的新日志实例
     */
    public function withChannel(string $channel): LoggerInterface
    {
        $clone = clone $this;
        $clone->channel = $channel;
        return $clone;
    }

    /**
     * 设置是否使用紧凑格式
     */
    public function setCompact(bool $compact): self
    {
        $this->compact = $compact;
        return $this;
    }

    /**
     * 获取处理器
     */
    public function getHandler(): HandlerInterface
    {
        return $this->handler;
    }

    /**
     * 设置处理器
     */
    public function setHandler(HandlerInterface $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    /**
     * 获取格式化器
     */
    public function getFormatter(): LogFormatter
    {
        return $this->formatter;
    }

    /**
     * 设置格式化器
     */
    public function setFormatter(LogFormatter $formatter): self
    {
        $this->formatter = $formatter;
        return $this;
    }

    /**
     * 刷新缓冲区
     */
    public function flush(): void
    {
        $this->handler->flush();
    }
}
