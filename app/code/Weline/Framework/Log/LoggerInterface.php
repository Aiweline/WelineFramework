<?php

declare(strict_types=1);

/**
 * Weline Framework 统一日志接口
 * 
 * PSR-3 风格的日志接口，所有日志实现必须实现此接口
 */

namespace Weline\Framework\Log;

interface LoggerInterface
{
    /**
     * 系统不可用
     */
    public function emergency(string $message, array $context = []): void;

    /**
     * 必须立即采取行动
     */
    public function alert(string $message, array $context = []): void;

    /**
     * 紧急情况
     */
    public function critical(string $message, array $context = []): void;

    /**
     * 运行时错误
     */
    public function error(string $message, array $context = []): void;

    /**
     * 警告但不是错误
     */
    public function warning(string $message, array $context = []): void;

    /**
     * 普通但重要的事件
     */
    public function notice(string $message, array $context = []): void;

    /**
     * 有趣的事件
     */
    public function info(string $message, array $context = []): void;

    /**
     * 详细的调试信息
     */
    public function debug(string $message, array $context = []): void;

    /**
     * 记录任意级别的日志
     *
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * 获取当前通道名
     */
    public function getChannel(): string;

    /**
     * 创建一个指定通道的新日志实例
     */
    public function withChannel(string $channel): self;
}
