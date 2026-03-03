<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Framework\System\Process\Processer;

/**
 * 服务实例信息值对象
 *
 * 表示单个服务实例（如一个 Worker、一个 Dispatcher、一个 Session Server）的信息。
 * 用于 ServerInstanceInfo 的 services 字段，提供统一的服务实例数据结构。
 */
class ServiceInfo
{
    public function __construct(
        public readonly string $role,
        public readonly string $displayName,
        public readonly int $instanceId,
        public readonly int $pid,
        public readonly ?int $port,
        public readonly string $state,
        public readonly int $epoch = 1,
        public readonly string $launchId = '',
        public readonly float $startedAt = 0,
        public readonly ?int $ipcClientId = null,
    ) {}

    /**
     * 检查服务是否正在运行
     * 
     * 基于 state 字段判断（来自实例文件的持久化状态），无需实时端口/进程检测
     * 实时检测开销大（每个服务一次系统调用），导致 status 命令很慢
     */
    public function isRunning(): bool
    {
        return \in_array($this->state, [
            ServiceInstance::STATE_READY,
            ServiceInstance::STATE_REGISTERED,
            ServiceInstance::STATE_STARTING,
        ], true);
    }
    
    /**
     * 实时检测服务是否运行（通过端口/进程检测）
     * 
     * 较慢，仅在需要实时验证时使用
     */
    public function isRunningRealtime(): bool
    {
        if ($this->port !== null && $this->port > 0) {
            return Processer::isPortInUse($this->port);
        }
        if ($this->pid > 0) {
            return Processer::processExists($this->pid);
        }
        return false;
    }

    /**
     * 检查服务是否处于健康状态
     */
    public function isHealthy(): bool
    {
        return \in_array($this->state, [ServiceInstance::STATE_READY, ServiceInstance::STATE_REGISTERED], true);
    }

    /**
     * 获取运行时长（秒）
     */
    public function getUptime(): float
    {
        if ($this->startedAt <= 0) {
            return 0;
        }
        return \microtime(true) - $this->startedAt;
    }

    /**
     * 获取状态显示文本
     */
    public function getStateDisplayText(): string
    {
        return match ($this->state) {
            ServiceInstance::STATE_PENDING => '等待中',
            ServiceInstance::STATE_STARTING => '启动中',
            ServiceInstance::STATE_REGISTERED => '已注册',
            ServiceInstance::STATE_READY => '运行中',
            ServiceInstance::STATE_DRAINING => '排水中',
            ServiceInstance::STATE_STOPPING => '停止中',
            ServiceInstance::STATE_STOPPED => '已停止',
            ServiceInstance::STATE_FAILED => '失败',
            default => $this->state,
        };
    }

    /**
     * 从 ServiceInstance 创建
     */
    public static function fromServiceInstance(ServiceInstance $instance, string $displayName): self
    {
        return new self(
            role: $instance->role,
            displayName: $displayName,
            instanceId: $instance->instanceId,
            pid: $instance->pid,
            port: $instance->port,
            state: $instance->state,
            epoch: $instance->epoch,
            launchId: $instance->launchId,
            startedAt: $instance->startedAt,
            ipcClientId: $instance->ipcClientId,
        );
    }

    /**
     * 从数组创建
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) ($data['role'] ?? ''),
            displayName: (string) ($data['display_name'] ?? $data['role'] ?? ''),
            instanceId: (int) ($data['instance_id'] ?? 0),
            pid: (int) ($data['pid'] ?? 0),
            port: isset($data['port']) ? (int) $data['port'] : null,
            state: (string) ($data['state'] ?? ServiceInstance::STATE_STOPPED),
            epoch: (int) ($data['epoch'] ?? 1),
            launchId: (string) ($data['launch_id'] ?? ''),
            startedAt: (float) ($data['started_at'] ?? 0),
            ipcClientId: isset($data['ipc_client_id']) ? (int) $data['ipc_client_id'] : null,
        );
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'display_name' => $this->displayName,
            'instance_id' => $this->instanceId,
            'pid' => $this->pid,
            'port' => $this->port,
            'state' => $this->state,
            'epoch' => $this->epoch,
            'launch_id' => $this->launchId,
            'started_at' => $this->startedAt,
            'ipc_client_id' => $this->ipcClientId,
        ];
    }
}
