<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Server\Service\ServiceOrchestrator;

/**
 * 服务提供者接口
 *
 * 每种子进程类型实现此接口，描述如何启动、配置、管理该类型的进程。
 * Master 通过此接口统一管理所有子进程，无需硬编码任何进程类型。
 */
interface ServiceProviderInterface
{
    /**
     * 获取服务角色标识（唯一，用于 IPC 识别）
     *
     * @return string 如 'worker', 'dispatcher', 'session_server', 'websocket', 'queue_worker'
     */
    public function getRole(): string;

    /**
     * 获取服务显示名称（用于日志/状态展示）
     */
    public function getDisplayName(): string;

    /**
     * 是否启用此服务
     *
     * 由 Provider 内部读取 env.php 配置或其他条件判断
     */
    public function isEnabled(ServiceContext $context): bool;

    /**
     * 获取服务实例数量
     *
     * @return int 实例数量（如 Worker 可能是 4 个，Dispatcher 是 1 个）
     */
    public function getInstanceCount(ServiceContext $context): int;

    /**
     * 获取启动优先级（数字越小越先启动，越后停止）
     *
     * 推荐值：
     * - 10: 基础设施服务（如 Session Server）
     * - 20: Worker 进程
     * - 30: 流量入口（Dispatcher）
     * - 40: 辅助服务（HTTP Redirect）
     * - 50+: 扩展服务
     */
    public function getPriority(): int;

    /**
     * 获取复活优先级（Master 意外死亡后的复活顺序）
     *
     * @return int 0=不参与复活, 1=最高优先级, 数字越大延迟越久
     */
    public function getResurrectionPriority(): int;

    /**
     * 获取重载策略
     *
     * @return string 'graceful'=排水后重启, 'immediate'=立即重启, 'none'=不支持重载
     */
    public function getReloadStrategy(): string;

    /**
     * 是否需要在启动阶段等待 READY 后再继续后续服务启动
     */
    public function requiresStartupReadyBarrier(): bool;

    /**
     * 是否支持 DRAIN（优雅排水）
     */
    public function supportsDrain(): bool;

    /**
     * 是否支持 SHUTDOWN 控制命令
     */
    public function supportsShutdown(): bool;

    /**
     * 是否支持重载命令
     */
    public function supportsReload(): bool;

    /**
     * 是否属于核心关键角色（用于断连升级策略）
     */
    public function isCriticalRole(): bool;

    /**
     * 构建启动命令
     *
     * @param int $instanceId 实例 ID（从 1 开始）
     * @param ServiceContext $context 启动上下文（端口、配置等）
     * @return ServiceCommand 启动命令对象
     */
    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand;

    /**
     * 获取服务端口（如果有）
     *
     * @param int $instanceId 实例 ID
     * @param ServiceContext $context 服务上下文
     * @return int|null 端口号，无端口返回 null
     */
    public function getPort(int $instanceId, ServiceContext $context): ?int;

    /**
     * 健康检查
     *
     * @param ServiceInstance $instance 服务实例
     * @return HealthCheckResult 健康检查结果
     */
    public function healthCheck(ServiceInstance $instance): HealthCheckResult;

    /**
     * 处理来自该服务的 IPC 消息（可选扩展）
     *
     * @param array $message IPC 消息
     * @param ServiceInstance $instance 发送消息的实例
     * @param ServiceOrchestrator $orchestrator 编排器（用于跨服务通信）
     * @return bool 是否已处理（false 表示交给默认处理）
     */
    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool;

    /**
     * 服务启动后回调（可选）
     */
    public function onStarted(ServiceInstance $instance): void;

    /**
     * 服务停止后回调（可选）
     */
    public function onStopped(ServiceInstance $instance): void;
}
