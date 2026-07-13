<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;

final class ProtocolEdgeControlHandler implements RoleControlHandlerInterface
{
    /**
     * @param callable():void $onShutdown
     * @param callable():void $onCertificateReload
     */
    public function __construct(
        private readonly mixed $onShutdown,
        private readonly mixed $onCertificateReload,
    ) {
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        $type = (string)($message['type'] ?? '');
        if ($type !== ControlMessage::TYPE_SHUTDOWN && $kernel->hasReceivedShutdown()) {
            return;
        }

        match ($type) {
            ControlMessage::TYPE_SHUTDOWN, ControlMessage::TYPE_DRAIN => ($this->onShutdown)(),
            ControlMessage::TYPE_SSL_CERT_RELOAD => ($this->onCertificateReload)(),
            default => null,
        };
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        if ($receivedShutdown || $kernel->hasReceivedShutdown()) {
            ($this->onShutdown)();
            return;
        }

        WlsLogger::warning_('[ProtocolEdge] Master IPC disconnected; keeping data plane alive while reconnecting.');
        $kernel->reconnect();
    }
}
