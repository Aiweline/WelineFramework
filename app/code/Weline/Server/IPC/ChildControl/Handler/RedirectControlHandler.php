<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;

final class RedirectControlHandler implements RoleControlHandlerInterface
{
    /**
     * @param callable(bool):void $setShutdownFlag
     */
    public function __construct(
        private readonly mixed $setShutdownFlag
    ) {
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        if (($message['type'] ?? '') === ControlMessage::TYPE_SHUTDOWN) {
            ($this->setShutdownFlag)(true);
        }
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        if ($receivedShutdown) {
            WlsLogger::info_('Master 连接断开（已收到 shutdown，不复活）');
            return;
        }
        WlsLogger::warning_('Master 连接意外断开，控制面已收口，不执行子进程复活。');
        $kernel->reconnect();
    }
}

