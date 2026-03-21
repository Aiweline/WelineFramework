<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

/**
 * WLS 角色消息处理器接口
 *
 * 专用于 WLS 子进程（参数类型为 SubprocessControlKernel）。
 * 通用版请参见 \Weline\Framework\System\IPC\IpcRoleHandlerInterface。
 */
interface RoleControlHandlerInterface
{
    public function onMessage(array $message, SubprocessControlKernel $kernel): void;

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void;
}
