<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl\Handler;

use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;

final class SessionServerControlHandler implements RoleControlHandlerInterface
{
    /**
     * @param callable():void $onDrain
     * @param callable():void $onShutdown
     * @param callable():void $onCacheClear
     */
    public function __construct(
        private readonly mixed $onDrain,
        private readonly mixed $onShutdown,
        private readonly mixed $onCacheClear,
    ) {
    }

    public function onMessage(array $message, SubprocessControlKernel $kernel): void
    {
        $type = $message['type'] ?? '';
        // 帝王令：已收 shutdown 后不再处理 DRAIN/CACHE_CLEAR 等
        if ($type !== ControlMessage::TYPE_SHUTDOWN && $kernel->hasReceivedShutdown()) {
            return;
        }
        switch ($type) {
            case ControlMessage::TYPE_DRAIN:
                WlsLogger::info_('Received drain signal, completing immediately...');
                ($this->onDrain)();
                break;
            case ControlMessage::TYPE_SHUTDOWN:
                WlsLogger::info_('Received shutdown signal, stopping...');
                ($this->onShutdown)();
                break;
            case ControlMessage::TYPE_CACHE_CLEAR:
                WlsLogger::info_('Received cache_clear, persisting sessions...');
                ($this->onCacheClear)();
                break;
            default:
                WlsLogger::debug_("Received unknown message type: {$type}");
        }
    }

    public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
    {
        if ($receivedShutdown || $kernel->hasReceivedShutdown()) {
            WlsLogger::info_('收到 Master shutdown 信号，Session Server 优雅退出');
            ($this->onShutdown)();
            return;
        }

        WlsLogger::warning_('Master 连接异常，Session Server 保持运行并等待 IPC 重连 / 孤儿保护判定');
    }
}

