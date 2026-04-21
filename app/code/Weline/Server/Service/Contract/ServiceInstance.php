<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Server\IPC\ControlMessage;

/**
 * 运行中的服务实例
 */
class ServiceInstance
{
    public const STATE_PENDING = 'pending';
    public const STATE_STARTING = 'starting';
    public const STATE_REGISTERED = 'registered';
    public const STATE_READY = 'ready';
    public const STATE_DRAINING = 'draining';
    public const STATE_STOPPING = 'stopping';
    public const STATE_STOPPED = 'stopped';
    public const STATE_FAILED = 'failed';

    public function __construct(
        public readonly string $role,
        public readonly int $instanceId,
        public int $epoch = 1,
        public string $launchId = '',
        public int $pid = 0,
        public ?int $port = null,
        public string $state = self::STATE_PENDING,
        public int $restarts = 0,
        public float $startedAt = 0,
        public float $lastHealthCheck = 0,
        public ?int $ipcClientId = null,
        public array $metadata = [],
        /** 进程归属类型：'framework' | 'module' */
        public string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        /** 模块代码（仅 module 类进程有效，如 'Weline_Payment'） */
        public string $moduleCode = '',
        public int $rootPid = 0,
        public int $launcherPid = 0,
    ) {}

    /**
     * 获取唯一标识键
     */
    public function getKey(): string
    {
        return "{$this->role}:{$this->instanceId}";
    }

    public function isFrameworkProcess(): bool
    {
        return $this->processKind === ControlMessage::PROCESS_KIND_FRAMEWORK;
    }

    public function isModuleProcess(): bool
    {
        return $this->processKind === ControlMessage::PROCESS_KIND_MODULE;
    }

    /**
     * 是否处于健康状态
     */
    public function isHealthy(): bool
    {
        return \in_array($this->state, [self::STATE_READY, self::STATE_REGISTERED], true);
    }

    /**
     * 是否正在运行
     */
    public function isRunning(): bool
    {
        return \in_array($this->state, [
            self::STATE_STARTING,
            self::STATE_REGISTERED,
            self::STATE_READY,
            self::STATE_DRAINING,
        ], true);
    }

    /**
     * 是否已停止
     */
    public function isStopped(): bool
    {
        return \in_array($this->state, [self::STATE_STOPPED, self::STATE_FAILED], true);
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
     * 设置元数据
     */
    public function setMeta(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * 获取元数据
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setProcessTreePids(int $pid, int $rootPid = 0, int $launcherPid = 0): void
    {
        $this->pid = \max(0, $pid);

        $normalizedRootPid = \max(0, $rootPid);
        if ($normalizedRootPid <= 0 && $this->pid > 0) {
            $normalizedRootPid = $this->pid;
        }
        $this->rootPid = $normalizedRootPid;

        $normalizedLauncherPid = \max(0, $launcherPid);
        if ($normalizedLauncherPid <= 0 && $normalizedRootPid > 0) {
            $normalizedLauncherPid = $normalizedRootPid;
        }
        $this->launcherPid = $normalizedLauncherPid;
    }

    public function getRootPid(): int
    {
        if ($this->rootPid > 0) {
            return $this->rootPid;
        }

        return $this->pid > 0 ? $this->pid : 0;
    }

    public function getLauncherPid(): int
    {
        if ($this->launcherPid > 0) {
            return $this->launcherPid;
        }

        return $this->getRootPid();
    }

    public function getTrackingPid(): int
    {
        $rootPid = $this->getRootPid();
        if ($rootPid > 0) {
            return $rootPid;
        }

        return $this->pid > 0 ? $this->pid : 0;
    }

    /**
     * @return list<int>
     */
    public function getManagedPids(): array
    {
        $pids = [];
        foreach ([$this->pid, $this->rootPid, $this->launcherPid] as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    public function matchesManagedPid(int $pid): bool
    {
        return $pid > 0 && \in_array($pid, $this->getManagedPids(), true);
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $arr = [
            'role'              => $this->role,
            'instance_id'       => $this->instanceId,
            'epoch'             => $this->epoch,
            'launch_id'         => $this->launchId,
            'pid'               => $this->pid,
            'root_pid'          => $this->rootPid,
            'launcher_pid'      => $this->launcherPid,
            'tracking_pid'      => $this->getTrackingPid(),
            'port'              => $this->port,
            'state'             => $this->state,
            'restarts'          => $this->restarts,
            'started_at'        => $this->startedAt,
            'uptime'            => $this->getUptime(),
            'last_health_check' => $this->lastHealthCheck,
            'ipc_client_id'     => $this->ipcClientId,
            'metadata'          => $this->metadata,
            'process_kind'      => $this->processKind,
        ];
        if ($this->moduleCode !== '') {
            $arr['module_code'] = $this->moduleCode;
        }
        return $arr;
    }
}
