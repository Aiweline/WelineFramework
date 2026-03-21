<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * IPC 组件日志接口
 *
 * 框架 IPC 组件通过此接口输出日志，避免与具体日志实现耦合。
 * WLS 注入 WlsIpcLogger，Cron 注入 CliIpcLogger，测试注入 NullIpcLogger。
 */
interface IpcLoggerInterface
{
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
