<?php
declare(strict_types=1);

/**
 * WLS IPC - Master 自愈协调器接口
 *
 * 提供一个可替换边界，方便：
 *   - 生产环境注入真实的 MasterResurrectionCoordinator
 *   - 单测注入 no-op / spy 实现验证调用时机
 *   - 宏观 HA 蓝图（Supervisor + lease）阶段切换到新实现
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

interface ResurrectionCoordinatorInterface
{
    /**
     * 处理子进程控制会话断开事件
     *
     * @return bool true = 触发了复活流程（无论是否成功）；false = 判定不复活
     */
    public function handleDisconnect(
        int    $priority,
        string $instanceName,
        int    $controlPort,
        bool   $receivedShutdown
    ): bool;
}
