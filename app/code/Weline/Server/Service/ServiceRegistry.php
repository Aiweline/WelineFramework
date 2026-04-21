<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;

/**
 * 服务注册表
 *
 * 存储所有已加载的 Provider 和运行中的 Instance
 * 提供按 role/instanceId/pid/port/ipcClientId 查找
 */
class ServiceRegistry
{
    /** @var array<string, ServiceProviderInterface> [role => Provider] */
    private array $providers = [];

    /** @var array<string, array<int, ServiceInstance>> [role => [instanceId => Instance]] */
    private array $instances = [];

    /** @var array<int, ServiceInstance> [pid => Instance] 快速查找 */
    private array $pidIndex = [];

    private array $rootPidIndex = [];

    private array $launcherPidIndex = [];

    /** @var array<int, ServiceInstance> [port => Instance] 快速查找 */
    private array $portIndex = [];

    /** @var array<int, ServiceInstance> [ipcClientId => Instance] 快速查找 */
    private array $ipcClientIndex = [];

    /**
     * 注册服务提供者
     */
    public function registerProvider(ServiceProviderInterface $provider): void
    {
        $this->providers[$provider->getRole()] = $provider;
    }

    /**
     * 获取服务提供者
     */
    public function getProvider(string $role): ?ServiceProviderInterface
    {
        return $this->providers[$role] ?? null;
    }

    /**
     * 获取所有服务提供者（按优先级排序）
     *
     * @return ServiceProviderInterface[]
     */
    public function getAllProviders(): array
    {
        $providers = $this->providers;
        \uasort($providers, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
        return $providers;
    }

    /**
     * 获取所有服务提供者（按反向优先级排序，用于停止）
     *
     * @return ServiceProviderInterface[]
     */
    public function getAllProvidersReversed(): array
    {
        $providers = $this->providers;
        \uasort($providers, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
        return $providers;
    }

    /**
     * 检查是否已注册指定角色的 Provider
     */
    public function hasProvider(string $role): bool
    {
        return isset($this->providers[$role]);
    }

    /**
     * 获取 Provider 数量
     */
    public function getProviderCount(): int
    {
        return \count($this->providers);
    }

    /**
     * 添加服务实例
     */
    public function addInstance(ServiceInstance $instance): void
    {
        $this->instances[$instance->role][$instance->instanceId] = $instance;
        $this->updateIndexes($instance);
    }

    /**
     * 更新索引
     */
    private function updateIndexes(ServiceInstance $instance): void
    {
        foreach ($instance->getManagedPids() as $pid) {
            if ($pid === $instance->pid) {
                $this->pidIndex[$pid] = $instance;
            }
            if ($pid === $instance->getRootPid()) {
                $this->rootPidIndex[$pid] = $instance;
            }
            if ($pid === $instance->getLauncherPid()) {
                $this->launcherPidIndex[$pid] = $instance;
            }
        }
        if ($instance->port !== null) {
            $this->portIndex[$instance->port] = $instance;
        }
        if ($instance->ipcClientId !== null) {
            $this->ipcClientIndex[$instance->ipcClientId] = $instance;
        }
    }

    /**
     * 获取服务实例
     */
    public function getInstance(string $role, int $instanceId): ?ServiceInstance
    {
        return $this->instances[$role][$instanceId] ?? null;
    }

    /**
     * 获取指定角色的所有实例
     *
     * @return ServiceInstance[]
     */
    public function getInstancesByRole(string $role): array
    {
        return $this->instances[$role] ?? [];
    }

    /**
     * 获取所有实例
     *
     * @return ServiceInstance[]
     */
    public function getAllInstances(): array
    {
        $all = [];
        foreach ($this->instances as $roleInstances) {
            foreach ($roleInstances as $instance) {
                $all[] = $instance;
            }
        }
        return $all;
    }

    /**
     * 通过 PID 获取实例
     */
    public function getInstanceByPid(int $pid): ?ServiceInstance
    {
        return $this->pidIndex[$pid]
            ?? $this->rootPidIndex[$pid]
            ?? $this->launcherPidIndex[$pid]
            ?? null;
    }

    public function getInstanceByRootPid(int $pid): ?ServiceInstance
    {
        return $this->rootPidIndex[$pid] ?? null;
    }

    public function getInstanceByLauncherPid(int $pid): ?ServiceInstance
    {
        return $this->launcherPidIndex[$pid] ?? null;
    }

    public function getInstanceByManagedPid(int $pid): ?ServiceInstance
    {
        return $this->getInstanceByPid($pid);
    }

    /**
     * 通过端口获取实例
     */
    public function getInstanceByPort(int $port): ?ServiceInstance
    {
        return $this->portIndex[$port] ?? null;
    }

    /**
     * 通过 IPC 客户端 ID 获取实例
     */
    public function getInstanceByIpcClient(int $ipcClientId): ?ServiceInstance
    {
        return $this->ipcClientIndex[$ipcClientId] ?? null;
    }

    /**
     * 更新实例（PID/端口/IPC 客户端 ID 变化时重建索引）
     */
    public function updateInstance(ServiceInstance $instance): void
    {
        $this->cleanupIndexesForInstance($instance);
        $this->instances[$instance->role][$instance->instanceId] = $instance;
        $this->updateIndexes($instance);
    }

    /**
     * 清理实例的旧索引
     */
    private function cleanupIndexesForInstance(ServiceInstance $instance): void
    {
        foreach ($this->pidIndex as $pid => $inst) {
            if ($inst->role === $instance->role && $inst->instanceId === $instance->instanceId) {
                unset($this->pidIndex[$pid]);
            }
        }
        foreach ($this->rootPidIndex as $pid => $inst) {
            if ($inst->role === $instance->role && $inst->instanceId === $instance->instanceId) {
                unset($this->rootPidIndex[$pid]);
            }
        }
        foreach ($this->launcherPidIndex as $pid => $inst) {
            if ($inst->role === $instance->role && $inst->instanceId === $instance->instanceId) {
                unset($this->launcherPidIndex[$pid]);
            }
        }
        foreach ($this->portIndex as $port => $inst) {
            if ($inst->role === $instance->role && $inst->instanceId === $instance->instanceId) {
                unset($this->portIndex[$port]);
            }
        }
        foreach ($this->ipcClientIndex as $clientId => $inst) {
            if ($inst->role === $instance->role && $inst->instanceId === $instance->instanceId) {
                unset($this->ipcClientIndex[$clientId]);
            }
        }
    }

    /**
     * 移除服务实例
     */
    public function removeInstance(string $role, int $instanceId): void
    {
        $instance = $this->instances[$role][$instanceId] ?? null;
        if ($instance === null) {
            return;
        }

        foreach ($instance->getManagedPids() as $pid) {
            unset($this->pidIndex[$pid], $this->rootPidIndex[$pid], $this->launcherPidIndex[$pid]);
        }
        if ($instance->port !== null && isset($this->portIndex[$instance->port])) {
            unset($this->portIndex[$instance->port]);
        }
        if ($instance->ipcClientId !== null && isset($this->ipcClientIndex[$instance->ipcClientId])) {
            unset($this->ipcClientIndex[$instance->ipcClientId]);
        }

        unset($this->instances[$role][$instanceId]);

        if (empty($this->instances[$role])) {
            unset($this->instances[$role]);
        }
    }

    /**
     * 移除指定角色的所有实例
     */
    public function removeInstancesByRole(string $role): void
    {
        $instances = $this->instances[$role] ?? [];
        foreach ($instances as $instance) {
            $this->removeInstance($role, $instance->instanceId);
        }
    }

    /**
     * 获取实例总数
     */
    public function getInstanceCount(): int
    {
        $count = 0;
        foreach ($this->instances as $roleInstances) {
            $count += \count($roleInstances);
        }
        return $count;
    }

    /**
     * 获取指定角色的实例数量
     */
    public function getInstanceCountByRole(string $role): int
    {
        return \count($this->instances[$role] ?? []);
    }

    /**
     * 获取指定状态的实例
     *
     * @return ServiceInstance[]
     */
    public function getInstancesByState(string $state): array
    {
        $result = [];
        foreach ($this->instances as $roleInstances) {
            foreach ($roleInstances as $instance) {
                if ($instance->state === $state) {
                    $result[] = $instance;
                }
            }
        }
        return $result;
    }

    /**
     * 获取所有健康的实例
     *
     * @return ServiceInstance[]
     */
    public function getHealthyInstances(): array
    {
        $result = [];
        foreach ($this->instances as $roleInstances) {
            foreach ($roleInstances as $instance) {
                if ($instance->isHealthy()) {
                    $result[] = $instance;
                }
            }
        }
        return $result;
    }

    /**
     * 清空所有实例
     */
    public function clearInstances(): void
    {
        $this->instances = [];
        $this->pidIndex = [];
        $this->rootPidIndex = [];
        $this->launcherPidIndex = [];
        $this->portIndex = [];
        $this->ipcClientIndex = [];
    }

    /**
     * 清空所有（Provider + Instance）
     */
    public function clear(): void
    {
        $this->providers = [];
        $this->clearInstances();
    }

    /**
     * 获取状态快照（用于 status 命令）
     */
    public function getStatusSnapshot(): array
    {
        $status = [];
        foreach ($this->providers as $role => $provider) {
            $instances = [];
            foreach ($this->instances[$role] ?? [] as $instance) {
                $instances[$instance->instanceId] = $instance->toArray();
            }
            $status[$role] = [
                'display_name' => $provider->getDisplayName(),
                'priority' => $provider->getPriority(),
                'reload_strategy' => $provider->getReloadStrategy(),
                'resurrection_priority' => $provider->getResurrectionPriority(),
                'instances' => $instances,
            ];
        }
        return $status;
    }
}
