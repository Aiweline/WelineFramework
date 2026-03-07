<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;

final class DispatcherControlHandler implements RoleControlHandlerInterface
{
    /**
     * @param callable(array):void $onMessage
     * @param callable(bool):void $onDisconnect
     */
    public function __construct(
        private readonly mixed $onMessage,
        private readonly mixed $onDisconnect
    ) {
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        ($this->onMessage)($message);
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        ($this->onDisconnect)($receivedShutdown);
    }
}

