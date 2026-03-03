<?php

declare(strict_types=1);

/**
 * Weline Framework 日志处理器接口
 */

namespace Weline\Framework\Log\Handler;

use Weline\Framework\Log\LogLevel;

interface HandlerInterface
{
    /**
     * 写入日志
     *
     * @param LogLevel $level 日志级别
     * @param string $formattedMessage 已格式化的日志消息
     * @param string $channel 通道名
     * @return bool 是否写入成功
     */
    public function write(LogLevel $level, string $formattedMessage, string $channel): bool;

    /**
     * 关闭处理器，释放资源
     */
    public function close(): void;

    /**
     * 刷新缓冲区
     */
    public function flush(): void;
}
