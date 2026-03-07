<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

interface RoleControlHandlerInterface
{
    public function onMessage(array $message, SubprocessControlKernel $kernel): void;

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void;
}

