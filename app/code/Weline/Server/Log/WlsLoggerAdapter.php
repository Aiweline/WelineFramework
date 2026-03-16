<?php

declare(strict_types=1);

/**
 * WlsLogger 适配器
 *
 * 实现 Framework\Log\LoggerInterface，将 w_log_* 调用统一委托给 WlsLogger。
 * 调用方无需关心运行环境（WLS / FPM），均由 LoggerFactory 自动选择实现。
 */

namespace Weline\Server\Log;

use Weline\Framework\Log\LoggerInterface;

class WlsLoggerAdapter implements LoggerInterface
{
    private string $channel;

    /**
     * PSR-3 / Framework 级别 -> WLS 级别（WlsLogger 仅支持 DEBUG|INFO|NOTICE|WARNING|ERROR|FATAL）
     */
    private const LEVEL_MAP = [
        'EMERGENCY' => LogLevel::FATAL,
        'ALERT'     => LogLevel::FATAL,
        'CRITICAL'  => LogLevel::FATAL,
        'ERROR'     => LogLevel::ERROR,
        'WARNING'   => LogLevel::WARNING,
        'NOTICE'    => LogLevel::NOTICE,
        'INFO'      => LogLevel::INFO,
        'DEBUG'     => LogLevel::DEBUG,
    ];

    public function __construct(string $channel = 'app')
    {
        $this->channel = $channel;
    }

    /**
     * 将 Framework 级别映射为 WLS 级别
     */
    private static function mapLevel(string $level): string
    {
        $upper = \strtoupper($level);
        return self::LEVEL_MAP[$upper] ?? LogLevel::INFO;
    }

    /**
     * 带通道前缀的消息（非默认通道时便于在 wls.log 中区分）
     */
    private function messageWithChannel(string $message, array $context): array
    {
        if ($this->channel === 'app') {
            return [$message, $context];
        }
        $context['_channel'] = $this->channel;
        $messageWithChannel = "[{$this->channel}] {$message}";
        return [$messageWithChannel, $context];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        [$msg, $ctx] = $this->messageWithChannel($message, $context);
        $wlsLevel = self::mapLevel($level);
        WlsLogger::getInstance()->log($wlsLevel, $msg, $ctx);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function withChannel(string $channel): self
    {
        return new self($channel);
    }

    /**
     * 刷新 WlsLogger 缓冲区（供 LoggerFactory::reset/flushAll 调用）
     */
    public function flush(): void
    {
        WlsLogger::flush_(true);
    }
}
