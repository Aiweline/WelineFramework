<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\Log\WlsLogger;

final class DelegatingControlHandler implements RoleControlHandlerInterface
{
    private ?RoleControlHandlerInterface $delegate = null;

    public function setDelegate(RoleControlHandlerInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        if ($this->delegate !== null) {
            $this->delegate->onMessage($message, $kernel);
            return;
        }

        WlsLogger::debug_('[IPC] Message ignored before child bootstrap delegate is ready: ' . (string)($message['type'] ?? 'unknown'));
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        if ($this->delegate !== null) {
            $this->delegate->onDisconnect($receivedShutdown, $kernel);
            return;
        }

        WlsLogger::warning_('[IPC] Master disconnected before child bootstrap delegate was ready');
    }
}
