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
    /**
     * CLI 场景下同一次状态输出会多次查询同一 PID/端口，做进程级短缓存避免重复系统调用。
     * 这是进程级缓存，不携带请求态数据，无需注册 StateManager 重置。
     *
     * @var array<int,bool>
     */
    private static array $pidRunningCache = [];

    /**
     * @var array<int,bool>
     */
    private static array $portRunningCache = [];

    public function __construct(
        public readonly string $role,
        public readonly string $displayName,
        public readonly int $instanceId,
        public readonly int $pid,
        public readonly ?int $port,
        public readonly string $state,
        public readonly int $priority = 99,
        public readonly int $epoch = 1,
        public readonly string $launchId = '',
        public readonly float $startedAt = 0,
        public readonly ?int $ipcClientId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * 检查服务是否正在运行
     * 
     * 基于 state 字段判断（来自实例文件的持久化状态），无需实时端口/进程检测
     * 实时检测开销大（每个服务一次系统调用），导致 status 命令很慢
     */
    public function isRunning(): bool
    {
        // 已明确停止/失败，直接返回 false
        if (\in_array($this->state, [ServiceInstance::STATE_STOPPED, ServiceInstance::STATE_FAILED], true)) {
            return false;
        }

        // 有 PID 时必须以 PID 为准，避免“端口被其他实例占用”导致假阳性
        if ($this->pid > 0) {
            return $this->isPidRunning($this->pid);
        }

        // 无 PID 时回退到端口探测
        if ($this->port !== null && $this->port > 0) {
            return $this->isPortRunning($this->port);
        }

        // 最后才使用持久化状态（用于启动中但尚未拿到 PID/port 的短窗口）
        return \in_array($this->state, [
            ServiceInstance::STATE_READY,
            ServiceInstance::STATE_REGISTERED,
            ServiceInstance::STATE_STARTING,
            ServiceInstance::STATE_DRAINING,
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
            return $this->isPortRunning($this->port);
        }
        if ($this->pid > 0) {
            return $this->isPidRunning($this->pid);
        }
        return false;
    }

    /**
     * 检查服务是否处于健康状态
     */
    public function isHealthy(): bool
    {
        return $this->isRunning() && \in_array($this->state, [ServiceInstance::STATE_READY, ServiceInstance::STATE_REGISTERED], true);
    }

    private function isPidRunning(int $pid): bool
    {
        if (!isset(self::$pidRunningCache[$pid])) {
            self::$pidRunningCache[$pid] = Processer::processExists($pid);
        }
        return self::$pidRunningCache[$pid];
    }

    private function isPortRunning(int $port): bool
    {
        if (!isset(self::$portRunningCache[$port])) {
            self::$portRunningCache[$port] = Processer::isPortInUse($port);
        }
        return self::$portRunningCache[$port];
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
    public static function fromServiceInstance(ServiceInstance $instance, string $displayName, int $priority = 99): self
    {
        return new self(
            role: $instance->role,
            displayName: $displayName,
            instanceId: $instance->instanceId,
            pid: $instance->pid,
            port: $instance->port,
            state: $instance->state,
            priority: $priority,
            epoch: $instance->epoch,
            launchId: $instance->launchId,
            startedAt: $instance->startedAt,
            ipcClientId: $instance->ipcClientId,
            metadata: $instance->metadata,
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
            priority: (int) ($data['priority'] ?? 99),
            epoch: (int) ($data['epoch'] ?? 1),
            launchId: (string) ($data['launch_id'] ?? ''),
            startedAt: (float) ($data['started_at'] ?? 0),
            ipcClientId: isset($data['ipc_client_id']) ? (int) $data['ipc_client_id'] : null,
            metadata: \is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
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
            'priority' => $this->priority,
            'epoch' => $this->epoch,
            'launch_id' => $this->launchId,
            'started_at' => $this->startedAt,
            'ipc_client_id' => $this->ipcClientId,
            'metadata' => $this->metadata,
        ];
    }
}
