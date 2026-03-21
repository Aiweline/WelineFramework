<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * IPC 角色消息处理器接口
 *
 * 每个子进程角色（Worker、Dispatcher、Cron Task 等）实现此接口，
 * 处理来自 Master 的 IPC 消息以及连接断开事件。
 */
interface IpcRoleHandlerInterface
{
    /**
     * 处理来自 Master 的 IPC 消息
     *
     * @param array          $message 解码后的消息数组（含 'type' 字段）
     * @param IpcClient $client  IPC 客户端实例（可用于发送回复）
     */
    public function onMessage(array $message, IpcClient $client): void;

    /**
     * 处理与 Master 的连接断开事件
     *
     * @param bool           $receivedShutdown 断开前是否收到过 shutdown 消息
     * @param IpcClient $client           IPC 客户端实例
     */
    public function onDisconnect(bool $receivedShutdown, IpcClient $client): void;
}
