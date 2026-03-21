<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * 空日志实现（默认 no-op）
 *
 * 用于不需要 IPC 日志输出的场景（测试、嵌入式调用等）。
 */
final class NullIpcLogger implements IpcLoggerInterface
{
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
}
