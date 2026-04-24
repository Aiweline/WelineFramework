<?php
declare(strict_types=1);

/**
 * WLS IPC 控制通道 - Master 自愈协调器
 *
 * 职责：
 *   - 根据子进程控制会话的断开事件，编排 MasterResurrector 的 `should -> confirm -> attempt` 流程
 *   - 为各角色 Handler / SubprocessControlKernel 提供统一的接线入口，避免把自愈判断打散进各 Handler
 *
 * 不负责：
 *   - 决定当前进程的复活优先级（由 Master ACK 时下发给 ControlClient）
 *   - 决定控制端口（由 InstanceInfoGateway / resolveControlPort 提供）
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

use Weline\Server\Log\WlsLogger;

final class MasterResurrectionCoordinator implements ResurrectionCoordinatorInterface
{
    /** @var callable|null 工厂：function(int $priority, string $instanceName, int $controlPort): MasterResurrector */
    private $factory;

    /**
     * @param callable|null $factory  用于单测注入伪 MasterResurrector；默认 null → 直接 new
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory;
    }

    /**
     * 根据断开事件判断并尝试复活
     *
     * @param int    $priority         子进程当前的复活优先级（ControlMessage::RESURRECTION_*）
     * @param string $instanceName     实例名（跨项目/跨主机隔离边界）
     * @param int    $controlPort      Master 控制端口（<=0 表示未知，直接放弃）
     * @param bool   $receivedShutdown 是否收到过 shutdown
     * @return bool true = 已触发复活流程（无论是否成功）；false = 未触发
     */
    public function handleDisconnect(
        int    $priority,
        string $instanceName,
        int    $controlPort,
        bool   $receivedShutdown
    ): bool {
        if ($instanceName === '' || $controlPort <= 0) {
            return false;
        }

        $resurrector = $this->createResurrector($priority, $instanceName, $controlPort);

        if (!$resurrector->shouldResurrect($receivedShutdown)) {
            return false;
        }

        // Gap 1/2 防误判：等待一个 grace 窗，期间 Master 恢复就让步
        if (!$resurrector->confirmAfterGrace()) {
            WlsLogger::info_('[MasterResurrectionCoordinator] grace 窗内 Master 已恢复，放弃复活');
            return false;
        }

        WlsLogger::warning_(\sprintf(
            '[MasterResurrectionCoordinator] 触发 Master 自愈 (instance=%s, priority=%d, port=%d)',
            $instanceName,
            $priority,
            $controlPort
        ));

        try {
            $resurrector->attemptResurrect();
        } catch (\Throwable $e) {
            WlsLogger::error_('[MasterResurrectionCoordinator] 复活流程异常: ' . $e->getMessage());
        }

        return true;
    }

    private function createResurrector(int $priority, string $instanceName, int $controlPort): MasterResurrector
    {
        if ($this->factory !== null) {
            $instance = ($this->factory)($priority, $instanceName, $controlPort);
            if ($instance instanceof MasterResurrector) {
                return $instance;
            }
        }
        return new MasterResurrector($priority, $instanceName, '127.0.0.1', $controlPort);
    }
}
