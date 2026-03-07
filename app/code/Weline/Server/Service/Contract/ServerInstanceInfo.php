<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Framework\System\Process\Processer;

/**
 * 服务器实例信息值对象
 *
 * 表示一个完整的 WLS 服务器实例（如 default、api-server 等），
 * 包含 Master 进程信息和所有子服务实例信息。
 *
 * 这是统一的实例信息数据结构，所有命令（stop、status、restart 等）
 * 都应该通过 ServerInstanceManager 获取此对象，而不是直接解析实例文件。
 */
class ServerInstanceInfo
{

    /**
     * @param ServiceInfo[] $services 服务实例列表（按优先级排序）
     */
    public function __construct(
        public readonly string $name,
        public readonly int $masterPid,
        public readonly int $controlPort,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $sslEnabled,
        public readonly bool $dispatcherEnabled,
        public readonly int $workerCount,
        public readonly int $workerBasePort,
        public readonly int $httpRedirectPort,
        public readonly string $startedAt,
        public readonly int $startedTimestamp,
        public readonly array $services,
    ) {}

    /**
     * 检查 Master 进程是否运行中
     */
    public function isMasterRunning(): bool
    {
        return $this->masterPid > 0 && Processer::processExists($this->masterPid);
    }

    /**
     * 获取指定角色的服务实例列表
     *
     * @return ServiceInfo[]
     */
    public function getServicesByRole(string $role): array
    {
        return \array_filter($this->services, fn(ServiceInfo $s) => $s->role === $role);
    }

    /**
     * 获取 Session Server 实例（通常只有一个）
     */
    public function getSessionServer(): ?ServiceInfo
    {
        $services = $this->getServicesByRole('session_server');
        return \reset($services) ?: null;
    }

    /**
     * 获取所有 Worker 实例
     *
     * @return ServiceInfo[]
     */
    public function getWorkers(): array
    {
        return $this->getServicesByRole('worker');
    }

    /**
     * 获取 Dispatcher 实例（通常只有一个）
     */
    public function getDispatcher(): ?ServiceInfo
    {
        $services = $this->getServicesByRole('dispatcher');
        return \reset($services) ?: null;
    }

    /**
     * 获取 HTTP Redirect 实例（通常只有一个）
     */
    public function getRedirect(): ?ServiceInfo
    {
        $services = $this->getServicesByRole('redirect');
        return \reset($services) ?: null;
    }

    /**
     * 获取正在运行的 Worker 数量
     */
    public function getRunningWorkerCount(): int
    {
        $count = 0;
        foreach ($this->getWorkers() as $worker) {
            if ($worker->isRunning()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取所有服务的运行统计
     *
     * @return array{total: int, running: int, stopped: int}
     */
    public function getServiceStats(): array
    {
        $total = \count($this->services);
        $running = 0;
        foreach ($this->services as $service) {
            if ($service->isRunning()) {
                $running++;
            }
        }
        return [
            'total' => $total,
            'running' => $running,
            'stopped' => $total - $running,
        ];
    }

    /**
     * 检查实例是否完全运行中（所有服务都在运行）
     */
    public function isFullyRunning(): bool
    {
        if (!$this->isMasterRunning()) {
            return false;
        }
        foreach ($this->services as $service) {
            if (!$service->isRunning()) {
                return false;
            }
        }
        return true;
    }

    /**
     * 检查实例是否部分运行中
     */
    public function isPartiallyRunning(): bool
    {
        $stats = $this->getServiceStats();
        return $stats['running'] > 0 && $stats['running'] < $stats['total'];
    }

    /**
     * 检查实例是否完全停止
     */
    public function isFullyStopped(): bool
    {
        if ($this->isMasterRunning()) {
            return false;
        }
        foreach ($this->services as $service) {
            if ($service->isRunning()) {
                return false;
            }
        }
        return true;
    }

    /**
     * 获取监听地址字符串（如 https://127.0.0.1:9981）
     */
    public function getListenAddress(): string
    {
        $scheme = $this->sslEnabled ? 'https' : 'http';
        return "{$scheme}://{$this->host}:{$this->port}";
    }

    /**
     * 获取端口范围描述字符串
     * 
     * 使用实际的 Worker 端口列表，而不是配置的基础端口
     */
    public function getPortRangeDescription(): string
    {
        // 从实际服务列表中获取 Worker 端口
        $workerPorts = [];
        foreach ($this->getWorkers() as $worker) {
            if ($worker->port !== null && $worker->port > 0) {
                $workerPorts[] = $worker->port;
            }
        }
        \sort($workerPorts);

        $segments = [];
        if ($this->dispatcherEnabled) {
            $segments[] = 'Dispatcher:' . $this->port;
        }

        if (!empty($workerPorts)) {
            $workerPortStr = \count($workerPorts) > 2
                ? \min($workerPorts) . '-' . \max($workerPorts)
                : \implode(',', $workerPorts);
            $segments[] = 'Workers:' . $workerPortStr;
        } else {
            $endPort = $this->port + $this->workerCount - 1;
            $segments[] = 'Workers:' . $this->port . '-' . $endPort;
        }

        // 其余服务端口也动态展示，确保 status 顶部与下方服务树一致
        $extraRoles = ['session_server' => 'Session', 'memory_server' => 'Memory', 'redirect' => 'Redirect'];
        foreach ($extraRoles as $role => $label) {
            $instances = $this->getServicesByRole($role);
            $ports = [];
            foreach ($instances as $service) {
                if ($service->port !== null && $service->port > 0) {
                    $ports[] = $service->port;
                }
            }
            if (!empty($ports)) {
                \sort($ports);
                $segments[] = $label . ':' . \implode(',', $ports);
            }
        }

        return \implode(', ', $segments);
    }

    /**
     * 按角色优先级排序服务列表
     *
     * @param ServiceInfo[] $services
     * @return ServiceInfo[]
     */
    public static function sortServicesByPriority(array $services): array
    {
        \usort($services, function (ServiceInfo $a, ServiceInfo $b) {
            $priorityA = $a->priority;
            $priorityB = $b->priority;
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }
            return $a->instanceId <=> $b->instanceId;
        });
        return $services;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $servicesArray = [];
        foreach ($this->services as $service) {
            $role = $service->role;
            if (!isset($servicesArray[$role])) {
                $servicesArray[$role] = [
                    'display_name' => $service->displayName,
                    'instances' => [],
                ];
            }
            $servicesArray[$role]['instances'][] = $service->toArray();
        }

        return [
            'name' => $this->name,
            'master_pid' => $this->masterPid,
            'control_port' => $this->controlPort,
            'host' => $this->host,
            'port' => $this->port,
            'ssl_enabled' => $this->sslEnabled,
            'dispatcher_enabled' => $this->dispatcherEnabled,
            'worker_count' => $this->workerCount,
            'worker_base_port' => $this->workerBasePort,
            'http_redirect_port' => $this->httpRedirectPort,
            'started_at' => $this->startedAt,
            'started_timestamp' => $this->startedTimestamp,
            'services' => $servicesArray,
        ];
    }
}
