<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;

class WorkerControlHandler implements RoleControlHandlerInterface
{
    /**
     * @param callable(array):void $onMessage
     * @param callable():void $onUnexpectedDisconnect
     */
    public function __construct(
        private readonly mixed $onMessage,
        private readonly mixed $onUnexpectedDisconnect
    ) {
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        ($this->onMessage)($message);
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        if ($receivedShutdown || $kernel->hasReceivedShutdown()) {
            WlsLogger::info_('Master 连接断开（已收到 shutdown，不复活）');
            return;
        }

        WlsLogger::warning_('Master 连接意外断开，控制面已收口，不执行子进程复活。');
        ($this->onUnexpectedDisconnect)();
    }
}

