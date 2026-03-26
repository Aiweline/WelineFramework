<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

/**
 * 服务器实例管理器（单一职责）
 *
 * 统一管理所有 WLS 实例信息的读取、写入、更新。
 * 所有命令（stop、status、restart、listing 等）都应该通过此类获取实例信息，
 * 而不是直接解析实例文件。
 *
 * SOLID 原则：
 * - 单一职责：只负责实例信息的管理
 * - 开闭原则：新增服务类型无需修改此类（通过 services 数组扩展）
 * - 依赖倒置：命令依赖此接口而非具体字段名
 */
class ServerInstanceManager
{
    /** 实例信息存储相对路径（相对 VAR_DIR） */
    public const INSTANCE_SUBDIR = 'server' . \DIRECTORY_SEPARATOR . 'instances' . \DIRECTORY_SEPARATOR;

    /** 服务角色显示名称映射 */
    private const ROLE_DISPLAY_NAMES = [
        'session_server' => 'Session Server',
        'worker' => 'HTTP Worker',
        'dispatcher' => 'Dispatcher',
        'redirect' => 'HTTP Redirect',
        'maintenance' => 'Maintenance Worker',
        'memory_server' => 'Memory Service',
        'memory_cache' => 'Memory Cache Service',
        'memory_session' => 'Memory Session Service',
    ];

    /** 服务角色优先级映射（用于旧格式回填） */
    private const ROLE_PRIORITIES = [
        'session_server' => 10,
        'worker' => 20,
        'dispatcher' => 30,
        'redirect' => 40,
        'maintenance' => 50,
        'memory_server' => 12,
        'memory_cache' => 15,
        'memory_session' => 16,
    ];

    /**
     * 获取实例信息目录
     */
    public function getInstanceDir(): string
    {
        return Env::VAR_DIR . self::INSTANCE_SUBDIR;
    }

    /**
     * 获取实例文件路径
     */
    public function getInstanceFile(string $name): string
    {
        return $this->getInstanceDir() . $name . '.json';
    }

    /**
     * 检查实例是否存在
     */
    public function hasInstance(string $name): bool
    {
        return $this->getRawInstanceData($name) !== null;
    }

    /**
     * 获取实例的完整信息（统一入口）
     *
     * 所有命令都应该通过此方法获取实例信息，而不是直接解析实例文件。
     */
    public function getInstanceInfo(string $name, bool $validateStale = true): ?ServerInstanceInfo
    {
        $rawData = $this->getRawInstanceData($name);
        if ($rawData === null) {
            return null;
        }

        $info = $this->buildInstanceInfo($name, $rawData);
        if ($validateStale && $this->isStaleInstanceRecord($name, $rawData, $info)) {
            $this->cleanupStaleInstanceArtifacts($name, $rawData);
            return null;
        }

        return $info;
    }

    /**
     * 获取所有实例的完整信息
     *
     * @return ServerInstanceInfo[]
     */
    public function getAllInstanceInfo(bool $validateStale = true): array
    {
        $instances = [];
        foreach ($this->listRawInstanceNames() as $name) {
            $info = $this->getInstanceInfo($name, $validateStale);
            if ($info !== null) {
                $instances[$name] = $info;
            }
        }
        return $instances;
    }

    /**
     * 获取所有实例名称
     *
     * @return string[]
     */
    public function listInstanceNames(bool $validateStale = true): array
    {
        return \array_keys($this->getAllInstanceInfo($validateStale));
    }

    /**
     * 控制面快路径：仅列出实例文件名，不做陈旧实例实时校验。
     *
     * 用于 reload/cache-clear 这类 CLI/IPC 调度前置判断，避免被 Windows 进程探测拖慢。
     *
     * @return string[]
     */
    public function listPersistedInstanceNames(): array
    {
        return $this->listRawInstanceNames();
    }

    /**
     * Resolve a user-provided instance name against persisted instances.
     *
     * Exact matches win first. If there is no exact hit, a unique
     * case-insensitive prefix match is accepted.
     */
    public function resolvePersistedInstanceName(string $name): ?string
    {
        $name = \trim($name);
        if ($name === '') {
            return null;
        }

        $persistedNames = $this->listPersistedInstanceNames();
        if ($persistedNames === []) {
            return null;
        }

        foreach ($persistedNames as $candidate) {
            if ($candidate === $name) {
                return $candidate;
            }
        }

        $lowerName = \strtolower($name);
        $caseInsensitiveExactMatches = [];
        $prefixMatches = [];

        foreach ($persistedNames as $candidate) {
            $candidateLower = \strtolower($candidate);
            if ($candidateLower === $lowerName) {
                $caseInsensitiveExactMatches[] = $candidate;
                continue;
            }

            if (\str_starts_with($candidateLower, $lowerName)) {
                $prefixMatches[] = $candidate;
            }
        }

        if (\count($caseInsensitiveExactMatches) === 1) {
            return $caseInsensitiveExactMatches[0];
        }

        if (\count($prefixMatches) === 1) {
            return $prefixMatches[0];
        }

        return null;
    }

    /**
     * Return the closest persisted instance names for a user-provided input.
     *
     * Prefix matches are preferred, then substring matches, then short-edit-distance
     * candidates. The result is deterministic and trimmed to $limit items.
     *
     * @return string[]
     */
    public function suggestPersistedInstanceNames(string $name, int $limit = 3): array
    {
        $name = \trim($name);
        if ($name === '' || $limit <= 0) {
            return [];
        }

        $persistedNames = $this->listPersistedInstanceNames();
        if ($persistedNames === []) {
            return [];
        }

        $lowerName = \strtolower($name);
        $thresholdBase = \max(2, (int) \floor(\strlen($lowerName) / 3));
        $ranked = [];

        foreach ($persistedNames as $candidate) {
            $candidateLower = \strtolower($candidate);
            $score = null;
            $position = 0;

            if ($candidateLower === $lowerName) {
                $score = 0;
            } elseif (\str_starts_with($candidateLower, $lowerName)) {
                $score = 10 + (\strlen($candidateLower) - \strlen($lowerName));
            } else {
                $containsPos = \strpos($candidateLower, $lowerName);
                if ($containsPos !== false) {
                    $score = 30 + $containsPos;
                    $position = $containsPos;
                } else {
                    $distance = \levenshtein($lowerName, $candidateLower);
                    $threshold = \max($thresholdBase, (int) \floor(\strlen($candidateLower) / 3));
                    if ($distance > $threshold) {
                        continue;
                    }
                    $score = 60 + $distance;
                }
            }

            $ranked[] = [
                'name' => $candidate,
                'score' => $score,
                'position' => $position,
                'length' => \strlen($candidateLower),
            ];
        }

        \usort($ranked, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $left['score'] <=> $right['score'];
            }

            if ($left['position'] !== $right['position']) {
                return $left['position'] <=> $right['position'];
            }

            if ($left['length'] !== $right['length']) {
                return $left['length'] <=> $right['length'];
            }

            return \strcmp($left['name'], $right['name']);
        });

        return \array_values(\array_map(
            static fn(array $item): string => $item['name'],
            \array_slice($ranked, 0, $limit)
        ));
    }

    /**
     * 清理所有陈旧的实例记录
     */
    public function cleanupStaleInstances(): int
    {
        $cleaned = 0;

        foreach ($this->listRawInstanceNames() as $name) {
            $rawData = $this->getRawInstanceData($name);
            if ($rawData === null) {
                continue;
            }

            $info = $this->buildInstanceInfo($name, $rawData);
            if ($this->isStaleInstanceRecord($name, $rawData, $info)) {
                $this->cleanupStaleInstanceArtifacts($name, $rawData);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 根据端口查找正在运行的实例
     */
    public function findRunningInstanceNameByPort(int $port): ?string
    {
        foreach ($this->getAllInstanceInfo() as $name => $info) {
            if ($info->port === $port) {
                return $name;
            }

            if ($info->httpRedirectPort > 0 && $info->httpRedirectPort === $port) {
                return $name;
            }

            foreach ($info->services as $service) {
                if ($service->port !== null && (int)$service->port === $port && $service->isRunning()) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * 更新实例的服务信息（Orchestrator 调用）
     *
     * @param ServiceInfo[] $services 服务实例列表
     */
    public function updateServices(string $name, array $services): void
    {
        $file = $this->getInstanceFile($name);
        if (!\is_file($file)) {
            return;
        }

        $content = @\file_get_contents($file);
        if ($content === false) {
            return;
        }

        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            $data = [];
        }

        // 构建 services 数组（按角色分组）
        $servicesArray = [];
        foreach ($services as $service) {
            $role = $service->role;
            if (!isset($servicesArray[$role])) {
                $servicesArray[$role] = [
                    'display_name' => $service->displayName,
                    'instances' => [],
                ];
            }
            $servicesArray[$role]['instances'][] = $service->toArray();
        }

        $data['services'] = $servicesArray;
        $data['services_updated_at'] = \date('Y-m-d H:i:s');

        // 同时更新便于直接访问的旧字段（向后兼容）
        $this->updateLegacyFields($data, $services);

        $this->atomicWriteJson($file, $data);
    }

    /**
     * 更新实例的 Master PID（Orchestrator 启动完成后调用，供 server:status 等正确显示）
     */
    public function updateMasterPid(string $instanceName, int $masterPid): void
    {
        $file = $this->getInstanceFile($instanceName);
        if (!\is_file($file)) {
            return;
        }
        $this->atomicUpdateJson($file, function (array $data) use ($masterPid): array {
            $data['master_pid'] = $masterPid;
            return $data;
        });
    }

    /**
     * 注册单个服务实例（子进程调用或 Orchestrator 单个更新）
     */
    public function registerService(string $instanceName, ServiceInfo $service): void
    {
        $file = $this->getInstanceFile($instanceName);
        if (!\is_file($file)) {
            return;
        }

        $this->atomicUpdateJson($file, function (array $data) use ($service): array {
            $role = $service->role;
            if (!isset($data['services'])) {
                $data['services'] = [];
            }
            if (!isset($data['services'][$role])) {
                $data['services'][$role] = [
                    'display_name' => $service->displayName,
                    'instances' => [],
                ];
            }

            // 查找并更新已存在的实例，或添加新实例
            $found = false;
            foreach ($data['services'][$role]['instances'] as $i => $inst) {
                if (($inst['instance_id'] ?? 0) === $service->instanceId) {
                    $data['services'][$role]['instances'][$i] = $service->toArray();
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data['services'][$role]['instances'][] = $service->toArray();
            }

            $data['services_updated_at'] = \date('Y-m-d H:i:s');

            // 更新旧字段
            $this->updateSingleLegacyField($data, $service);

            return $data;
        });
    }

    /**
     * 删除实例文件
     */
    public function deleteInstance(string $name): bool
    {
        $file = $this->getInstanceFile($name);
        if (\is_file($file)) {
            return @\unlink($file);
        }
        return true;
    }

    /**
     * 获取原始实例数据（不解析为对象）
     */
    public function getRawInstanceData(string $name): ?array
    {
        $file = $this->getInstanceFile($name);
        if (!\is_file($file)) {
            return null;
        }
        $content = @\file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * 获取服务角色的显示名称
     */
    public function getDisplayName(string $role): string
    {
        return self::ROLE_DISPLAY_NAMES[$role] ?? \ucwords(\str_replace('_', ' ', $role));
    }

    /**
     * 获取服务角色优先级
     */
    public function getRolePriority(string $role): int
    {
        return self::ROLE_PRIORITIES[$role] ?? 99;
    }

    /**
     * 获取所有原始实例名称（不过滤陈旧记录）
     *
     * @return string[]
     */
    private function listRawInstanceNames(): array
    {
        $dir = $this->getInstanceDir();
        if (!\is_dir($dir)) {
            return [];
        }

        $files = \glob($dir . '*.json');
        if ($files === false) {
            return [];
        }

        return \array_map(static fn(string $path): string => \basename($path, '.json'), $files);
    }

    /**
     * 判断实例记录是否已陈旧
     */
    private function isStaleInstanceRecord(string $name, array $rawData, ?ServerInstanceInfo $info = null): bool
    {
        if ($this->isStartLockHeld($name)) {
            return false;
        }

        return !$this->hasTrackedRunningProcess($name, $rawData, $info);
    }

    /**
     * 检查实例是否仍有可确认的受管进程存活
     */
    private function hasTrackedRunningProcess(string $name, array $rawData, ?ServerInstanceInfo $info = null): bool
    {
        if ($info !== null && $info->isMasterRunning()) {
            return true;
        }

        foreach ($this->collectTrackedPids($name, $rawData) as $pid) {
            if ($pid > 0 && Processer::processExists($pid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 收集实例关联的受管 PID
     *
     * @return int[]
     */
    private function collectTrackedPids(string $name, array $rawData): array
    {
        $pids = [];
        $ignoredPids = $this->collectSharedExternalTrackedPids($rawData);

        foreach (['pid', 'master_pid', 'dispatcher_pid', 'redirect_pid', 'session_server_pid'] as $field) {
            $pid = (int) ($rawData[$field] ?? 0);
            if ($pid > 0 && !isset($ignoredPids[$pid])) {
                $pids[$pid] = true;
            }
        }

        foreach (($rawData['worker_pids'] ?? []) as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        foreach (($rawData['services'] ?? []) as $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }

            foreach (($roleData['instances'] ?? []) as $instanceData) {
                if (!\is_array($instanceData)) {
                    continue;
                }
                if ($this->isSharedExternalServiceRecord($instanceData)) {
                    continue;
                }

                $pid = (int) ($instanceData['pid'] ?? 0);
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        foreach ($this->collectIndexedPidsByInstance($name, $rawData) as $pid) {
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * 基于进程索引收集实例关联 PID
     *
     * @return int[]
     */
    private function collectIndexedPidsByInstance(string $name, array $rawData): array
    {
        $pidIndex = Processer::readPidIndex();
        if (empty($pidIndex)) {
            return [];
        }

        return $this->collectIndexedPidsByInstanceFromPidIndex($pidIndex, $name, $rawData);
    }

    /**
     * @param array<int, array{pname: string, jsonPath: string}> $pidIndex
     * @return int[]
     */
    private function collectIndexedPidsByInstanceFromPidIndex(array $pidIndex, string $name, array $rawData): array
    {
        $allowedTaskNames = [];
        foreach ($this->collectManagedProcessNames($name, $rawData) as $processName) {
            try {
                $allowedTaskNames[Processer::getTaskName($processName)] = true;
            } catch (\Throwable) {
                if (\str_starts_with($processName, '--name=')) {
                    $allowedTaskNames[\substr($processName, 7)] = true;
                }
            }
        }

        $pids = [];
        foreach ($pidIndex as $pid => $record) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }

            $jsonPath = (string) ($record['jsonPath'] ?? '');
            if ($jsonPath === '' || !\is_file($jsonPath)) {
                continue;
            }

            $pname = (string) ($record['pname'] ?? '');
            if ($pname === '') {
                continue;
            }

            try {
                $taskName = Processer::getTaskName($pname);
            } catch (\Throwable) {
                $taskName = \str_starts_with($pname, '--name=')
                    ? \substr($pname, 7)
                    : '';
            }

            if ($taskName !== '' && isset($allowedTaskNames[$taskName])) {
                $pids[$pid] = true;
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * 收集实例关联的受管进程名
     *
     * @return string[]
     */
    private function collectManagedProcessNames(string $name, array $rawData): array
    {
        $names = [
            '--name=' . MasterProcess::getMasterProcessName($name),
            '--name=weline-wls-dispatcher-' . $name,
            '--name=weline-wls-session-' . $name,
            '--name=' . MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name,
        ];

        foreach (($rawData['services'] ?? []) as $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }

            foreach (($roleData['instances'] ?? []) as $instanceData) {
                if (!\is_array($instanceData)) {
                    continue;
                }
                if ($this->isSharedExternalServiceRecord($instanceData)) {
                    continue;
                }

                $processName = (string) ($instanceData['metadata']['process_name'] ?? '');
                if ($processName !== '') {
                    $names[] = '--name=' . $processName;
                }
            }
        }

        $count = (int) ($rawData['count'] ?? 0);
        for ($i = 1; $i <= $count; $i++) {
            $names[] = '--name=weline-wls-worker-' . $name . '-' . $i;
            $names[] = '--name=weline-master-' . $name . '-worker-' . $i;
        }

        return \array_values(\array_unique(\array_filter($names)));
    }

    /**
     * 清理陈旧实例留下的文件痕迹
     */
    private function cleanupStaleInstanceArtifacts(string $name, array $rawData): void
    {
        foreach ($this->collectManagedProcessNames($name, $rawData) as $processName) {
            Processer::removePidFile($processName);
        }

        $pidFile = $this->getPidFile($name);
        if (\is_file($pidFile)) {
            @\unlink($pidFile);
        }

        $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $name . '.lock';
        if (\is_file($lockFile)) {
            @\unlink($lockFile);
        }

        $exceptionFile = MasterProcess::getServiceExceptionFile($name);
        if (\is_file($exceptionFile)) {
            @\unlink($exceptionFile);
        }

        $this->deleteInstance($name);
        Processer::cleanupStalePidFiles();
    }

    /**
     * 判断实例启动锁是否仍被持有
     */
    private function isStartLockHeld(string $name): bool
    {
        $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $name . '.lock';
        if (!\is_file($lockFile)) {
            return false;
        }

        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $locked = @\flock($fp, \LOCK_EX | \LOCK_NB);
        if ($locked) {
            @\flock($fp, \LOCK_UN);
        }

        @\fclose($fp);
        return !$locked;
    }

    /**
     * 从原始数据构建 ServerInstanceInfo 对象
     */
    private function buildInstanceInfo(string $name, array $rawData): ServerInstanceInfo
    {
        $services = $this->parseServices($rawData);

        return new ServerInstanceInfo(
            name: $name,
            masterPid: (int) ($rawData['master_pid'] ?? 0),
            controlPort: (int) ($rawData['control_port'] ?? 0),
            host: (string) ($rawData['host'] ?? '127.0.0.1'),
            port: (int) ($rawData['port'] ?? 0),
            sslEnabled: (bool) ($rawData['ssl_enabled'] ?? false),
            dispatcherEnabled: (bool) ($rawData['dispatcher_enabled'] ?? false),
            workerCount: (int) ($rawData['count'] ?? 0),
            workerBasePort: (int) ($rawData['worker_port'] ?? $rawData['port'] ?? 0),
            httpRedirectPort: (int) ($rawData['http_redirect_port'] ?? 0),
            startedAt: (string) ($rawData['started_at'] ?? ''),
            startedTimestamp: (int) ($rawData['started_timestamp'] ?? 0),
            services: $services,
        );
    }

    /**
     * 解析服务列表
     *
     * 优先使用 services 字段（新格式），否则从旧字段构建
     *
     * @return ServiceInfo[]
     */
    private function parseServices(array $rawData): array
    {
        $services = [];

        // 优先使用新格式的 services 字段
        if (!empty($rawData['services']) && \is_array($rawData['services'])) {
            foreach ($rawData['services'] as $role => $roleData) {
                $displayName = $roleData['display_name'] ?? $this->getDisplayName($role);
                $instances = $roleData['instances'] ?? [];
                foreach ($instances as $instData) {
                    $instData['role'] = $role;
                    $instData['display_name'] = $displayName;
                    if (!isset($instData['priority'])) {
                        $instData['priority'] = $this->getRolePriority($role);
                    }
                    $services[] = ServiceInfo::fromArray($instData);
                }
            }
        } else {
            // 从旧字段构建（向后兼容）
            $services = $this->buildServicesFromLegacyFields($rawData);
        }

        return ServerInstanceInfo::sortServicesByPriority($services);
    }

    /**
     * 从旧字段构建服务列表（向后兼容）
     *
     * @return ServiceInfo[]
     */
    private function buildServicesFromLegacyFields(array $rawData): array
    {
        $services = [];
        $workerCount = (int) ($rawData['count'] ?? 0);

        // Session Server
        $sessionServerPort = $this->getSessionServerPort();
        $sessionServerPid = (int) ($rawData['session_server_pid'] ?? 0);
        $services[] = new ServiceInfo(
            role: 'session_server',
            displayName: 'Session Server',
            instanceId: 1,
            pid: $sessionServerPid,
            port: $sessionServerPort,
            state: $this->guessState($sessionServerPid, $sessionServerPort),
            priority: $this->getRolePriority('session_server'),
        );

        // Workers
        $workerPids = $rawData['worker_pids'] ?? [];
        $workerPorts = $rawData['worker_ports'] ?? [];
        $workerBasePort = (int) ($rawData['worker_port'] ?? $rawData['port'] ?? 0);

        for ($i = 0; $i < $workerCount; $i++) {
            $pid = (int) ($workerPids[$i] ?? 0);
            $port = (int) ($workerPorts[$i] ?? ($workerBasePort + $i));
            $services[] = new ServiceInfo(
                role: 'worker',
                displayName: 'HTTP Worker',
                instanceId: $i + 1,
                pid: $pid,
                port: $port,
                state: $this->guessState($pid, $port),
                priority: $this->getRolePriority('worker'),
            );
        }

        // Dispatcher
        if (!empty($rawData['dispatcher_enabled'])) {
            $dispatcherPid = (int) ($rawData['dispatcher_pid'] ?? 0);
            $dispatcherPort = (int) ($rawData['dispatcher_port'] ?? $rawData['port'] ?? 0);
            $services[] = new ServiceInfo(
                role: 'dispatcher',
                displayName: 'Dispatcher',
                instanceId: 1,
                pid: $dispatcherPid,
                port: $dispatcherPort,
                state: $this->guessState($dispatcherPid, $dispatcherPort),
                priority: $this->getRolePriority('dispatcher'),
            );
        }

        // HTTP Redirect
        $redirectPort = (int) ($rawData['http_redirect_port'] ?? 0);
        if ($redirectPort > 0) {
            $redirectPid = (int) ($rawData['redirect_pid'] ?? 0);
            $services[] = new ServiceInfo(
                role: 'redirect',
                displayName: 'HTTP Redirect',
                instanceId: 1,
                pid: $redirectPid,
                port: $redirectPort,
                state: $this->guessState($redirectPid, $redirectPort),
                priority: $this->getRolePriority('redirect'),
            );
        }

        return $services;
    }

    /**
     * 根据 PID/端口猜测服务状态
     */
    private function guessState(int $pid, ?int $port): string
    {
        if ($pid > 0 && Processer::processExists($pid)) {
            return ServiceInstance::STATE_READY;
        }
        // 仅在缺失 PID 时，才用端口作为弱信号推断“可能运行中”。
        // 否则会把“其他实例占用同端口”误判为当前实例在线。
        if ($pid <= 0 && $port !== null && $port > 0 && Processer::isPortInUse($port)) {
            return ServiceInstance::STATE_READY;
        }
        return ServiceInstance::STATE_STOPPED;
    }

    /**
     * 获取 Session Server 端口（从 env 配置）
     */
    private function getSessionServerPort(): int
    {
        static $port = null;
        if ($port === null) {
            $envConfig = Env::getInstance()->getConfig();
            $port = (int) ($envConfig['session']['server_port'] ?? 19970);
        }
        return $port;
    }

    /**
     * 更新旧字段（向后兼容）
     *
     * @param ServiceInfo[] $services
     */
    private function updateLegacyFields(array &$data, array $services): void
    {
        $workerPids = [];
        $workerPorts = [];

        foreach ($services as $service) {
            switch ($service->role) {
                case 'session_server':
                    $data['session_server_pid'] = $service->pid;
                    $data['session_server_port'] = $service->port;
                    break;

                case 'worker':
                    if ($service->pid > 0) {
                        $workerPids[] = $service->pid;
                    }
                    if ($service->port !== null && $service->port > 0) {
                        $workerPorts[] = $service->port;
                    }
                    break;

                case 'dispatcher':
                    $data['dispatcher_pid'] = $service->pid;
                    $data['dispatcher_port'] = $service->port;
                    break;

                case 'redirect':
                    $data['redirect_pid'] = $service->pid;
                    break;
            }
        }

        if (!empty($workerPids)) {
            $data['worker_pids'] = $workerPids;
        }
        if (!empty($workerPorts)) {
            $data['worker_ports'] = $workerPorts;
        }
    }

    /**
     * 更新单个服务的旧字段
     */
    private function updateSingleLegacyField(array &$data, ServiceInfo $service): void
    {
        switch ($service->role) {
            case 'session_server':
                $data['session_server_pid'] = $service->pid;
                $data['session_server_port'] = $service->port;
                break;

            case 'dispatcher':
                $data['dispatcher_pid'] = $service->pid;
                $data['dispatcher_port'] = $service->port;
                break;

            case 'redirect':
                $data['redirect_pid'] = $service->pid;
                break;
        }
    }

    /**
     * 原子写入 JSON 文件
     */
    private function atomicWriteJson(string $file, array $data): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $tmpFile = $file . '.tmp.' . \getmypid();
        $json = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        if (@\file_put_contents($tmpFile, $json) === false) {
            return false;
        }

        if (@\rename($tmpFile, $file) === false) {
            @\unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * 原子更新 JSON 文件
     */
    private function atomicUpdateJson(string $file, callable $updater): bool
    {
        $content = @\file_get_contents($file);
        $data = $content !== false ? (\json_decode($content, true) ?: []) : [];

        $data = $updater($data);

        return $this->atomicWriteJson($file, $data);
    }

    // ========================================================================
    // 以下方法来自原 WlsInstanceRegistry，提供运行时状态检测能力
    // ========================================================================

    /**
     * 获取所有运行中的 Master 进程 PID 列表
     *
     * @return int[]
     */
    public function getRunningMasterPids(): array
    {
        $pids = [];
        foreach ($this->getAllInstanceInfo() as $info) {
            if ($info->isMasterRunning()) {
                $pids[] = $info->masterPid;
            }
        }
        return \array_values(\array_unique($pids));
    }

    /**
     * 获取所有实例的 Worker PID 列表（合并去重）
     *
     * @return int[]
     */
    public function getAllWorkerPids(): array
    {
        $all = [];
        foreach ($this->getAllInstanceInfo() as $info) {
            foreach ($info->getWorkers() as $worker) {
                if ($worker->pid > 0) {
                    $all[] = $worker->pid;
                }
            }
        }
        return \array_values(\array_unique($all));
    }

    /**
     * 获取所有真正运行中的 Worker PID 列表
     *
     * @return int[]
     */
    public function getRunningWorkerPids(): array
    {
        $runningPids = [];
        foreach ($this->getAllInstanceInfo() as $info) {
            foreach ($info->getWorkers() as $worker) {
                if ($worker->isRunning() && $worker->pid > 0) {
                    $runningPids[] = $worker->pid;
                }
            }
        }
        return \array_values(\array_unique($runningPids));
    }

    /**
     * 检查指定实例是否有服务在运行
     */
    public function isInstanceRunning(string $name): bool
    {
        $info = $this->getPersistedInstanceInfo($name);
        if ($info === null) {
            return false;
        }
        $stats = $this->collectRuntimeStatsForInstance($info, false);
        return $stats['instance_running'];
    }

    /**
     * 统计实例中真正运行的 Worker 数量
     */
    public function countRunningWorkers(string $name): int
    {
        $info = $this->getPersistedInstanceInfo($name);
        if ($info === null) {
            return 0;
        }
        return $this->collectRuntimeStatsForInstance($info, false)['workers'];
    }

    /**
     * 是否有任意运行中的 WLS Worker
     */
    public function hasRunningWorkers(): bool
    {
        foreach ($this->getAllPersistedInstanceInfo() as $info) {
            if ($this->collectRuntimeStatsForInstance($info, false)['workers'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取所有真正运行中的实例统计信息
     *
     * 默认走控制面快路径：基于实例文件中的活跃状态统计，而不是逐 Worker 做实时系统探测。
     *
     * @return array{instances: int, workers: int, dispatchers: int, ports: int[]}
     */
    public function getRunningStats(): array
    {
        $instances = 0;
        $workers = 0;
        $dispatchers = 0;
        $ports = [];

        foreach ($this->getAllPersistedInstanceInfo() as $info) {
            $runtimeStats = $this->collectRuntimeStatsForInstance($info, false);
            if ($runtimeStats['instance_running']) {
                $instances++;
            }
            $workers += $runtimeStats['workers'];
            $dispatchers += $runtimeStats['dispatchers'];
            $ports = \array_merge($ports, $runtimeStats['ports']);
        }

        return [
            'instances' => $instances,
            'workers' => $workers,
            'dispatchers' => $dispatchers,
            'ports' => $ports,
        ];
    }

    public function getPersistedInstanceInfo(string $name): ?ServerInstanceInfo
    {
        $rawData = $this->getRawInstanceData($name);
        if ($rawData === null) {
            return null;
        }

        return $this->buildInstanceInfo($name, $rawData);
    }

    /**
     * @return array<string, ServerInstanceInfo>
     */
    public function getAllPersistedInstanceInfo(): array
    {
        $instances = [];
        foreach ($this->listPersistedInstanceNames() as $name) {
            $info = $this->getPersistedInstanceInfo($name);
            if ($info !== null) {
                $instances[$name] = $info;
            }
        }

        return $instances;
    }

    /**
     * @return array{instance_running: bool, workers: int, dispatchers: int, ports: int[]}
     */
    public function getRuntimeStatsForInstance(ServerInstanceInfo $info, bool $realtime = false): array
    {
        return $this->collectRuntimeStatsForInstance($info, $realtime);
    }

    /**
     * @return array{instance_running: bool, workers: int, dispatchers: int, ports: int[]}
     */
    private function collectRuntimeStatsForInstance(ServerInstanceInfo $info, bool $realtime): array
    {
        $workers = 0;
        $dispatchers = 0;
        $ports = [];
        $hasActiveService = false;

        foreach ($info->services as $service) {
            if ($this->isSharedExternalServiceInfo($service)) {
                continue;
            }

            $isRunning = $realtime ? $service->isRunning() : $service->isExpectedRunningState();
            if (!$isRunning) {
                continue;
            }

            $hasActiveService = true;

            if ($service->role === 'worker') {
                $workers++;
                if ($service->port !== null) {
                    $ports[] = $service->port;
                }
                continue;
            }

            if ($service->role === 'dispatcher') {
                $dispatchers++;
            }
        }

        $masterRunning = $realtime ? $info->isMasterRunning() : $info->masterPid > 0;

        return [
            'instance_running' => $hasActiveService || $masterRunning,
            'workers' => $workers,
            'dispatchers' => $dispatchers,
            'ports' => $ports,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function collectSharedExternalTrackedPids(array $rawData): array
    {
        $pids = [];

        foreach (($rawData['services'] ?? []) as $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }

            foreach (($roleData['instances'] ?? []) as $instanceData) {
                if (!\is_array($instanceData) || !$this->isSharedExternalServiceRecord($instanceData)) {
                    continue;
                }

                $pid = (int) ($instanceData['pid'] ?? 0);
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        return $pids;
    }

    private function isSharedExternalServiceRecord(array $instanceData): bool
    {
        return (bool) ($instanceData['metadata']['shared_external'] ?? false);
    }

    private function isSharedExternalServiceInfo(ServiceInfo $service): bool
    {
        return (bool) ($service->metadata['shared_external'] ?? false);
    }

    // ========================================================================
    // 以下方法来自原 ServerInstanceService，提供实例写入和管理能力
    // ========================================================================

    /**
     * 保存实例信息（用于 Start 命令）
     */
    public function saveInstance(string $name, array $info): void
    {
        $file = $this->getInstanceFile($name);
        $dir = $this->getInstanceDir();
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $data = \array_merge([
            'name' => $name,
            'pid' => 0,
            'host' => '127.0.0.1',
            'port' => 8080,
            'count' => 4,
            'daemon' => false,
            'started_by' => $this->getCurrentUser(),
            'started_at' => \date('Y-m-d H:i:s'),
            'started_timestamp' => \time(),
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
        ], $info);

        $this->atomicWriteJson($file, $data);
    }

    /**
     * 获取实例 PID 文件路径
     */
    public function getPidFile(string $name): string
    {
        return $this->getInstanceDir() . $name . '.pid';
    }

    /**
     * 获取实例日志文件路径
     */
    public function getLogFile(string $name): string
    {
        $dir = WlsLogService::getLogDir($name);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir . $name . '.log';
    }

    /**
     * 更新实例 PID
     */
    public function updatePid(string $name, int $pid): void
    {
        $file = $this->getInstanceFile($name);
        if (!\is_file($file)) {
            return;
        }

        $this->atomicUpdateJson($file, function (array $data) use ($pid): array {
            $data['pid'] = $pid;
            return $data;
        });

        @\file_put_contents($this->getPidFile($name), (string) $pid);
    }

    /**
     * 获取当前用户
     */
    protected function getCurrentUser(): string
    {
        if (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN') {
            return \getenv('USERNAME') ?: \getenv('USER') ?: 'unknown';
        }
        if (\function_exists('posix_getpwuid') && \function_exists('posix_geteuid')) {
            $userInfo = \posix_getpwuid(\posix_geteuid());
            return $userInfo['name'] ?? 'unknown';
        }
        return \getenv('USER') ?: 'unknown';
    }

    /**
     * 格式化时长
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . __('秒');
        }
        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . __('分') . $secs . __('秒');
        }
        if ($seconds < 86400) {
            $hours = (int) ($seconds / 3600);
            $minutes = (int) (($seconds % 3600) / 60);
            return $hours . __('小时') . $minutes . __('分');
        }
        $days = (int) ($seconds / 86400);
        $hours = (int) (($seconds % 86400) / 3600);
        return $days . __('天') . $hours . __('小时');
    }

    /**
     * 获取所有原始实例数据
     *
     * @return array<string, array>
     */
    public function getAllRawInstanceData(): array
    {
        $out = [];
        foreach ($this->listInstanceNames() as $name) {
            $data = $this->getRawInstanceData($name);
            if ($data !== null) {
                $out[$name] = $data;
            }
        }
        return $out;
    }

    // ========================================================================
    // 静态方法（供 Start.php 等直接调用，带文件锁保护）
    // ========================================================================

    /**
     * 静态原子写入 JSON 文件（带排他锁）
     */
    public static function atomicWriteJsonStatic(string $file, array $data, int $timeout = 5): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $json = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        self::cleanupStaleTempFiles($file);

        $lockFile = $file . '.lock';
        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $locked = false;
        $startTime = \time();

        while (\time() - $startTime < $timeout) {
            if (\flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            SchedulerSystem::usleep(10000);
        }

        if (!$locked) {
            @\fclose($fp);
            return false;
        }

        try {
            $tempFile = $file . '.tmp.' . \getmypid();
            if (@\file_put_contents($tempFile, $json) === false) {
                return false;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                @\unlink($file);
            }

            $success = @\rename($tempFile, $file);
            if (!$success) {
                @\unlink($tempFile);
            }
            return $success;
        } finally {
            \flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }

    /**
     * 静态原子更新 JSON 文件（带排他锁）
     */
    public static function atomicUpdateJsonStatic(string $file, callable $modifier, int $timeout = 5): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        self::cleanupStaleTempFiles($file);

        $lockFile = $file . '.lock';
        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $locked = false;
        $startTime = \time();

        while (\time() - $startTime < $timeout) {
            if (\flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            SchedulerSystem::usleep(10000);
        }

        if (!$locked) {
            @\fclose($fp);
            return false;
        }

        try {
            $data = [];
            if (\is_file($file)) {
                $content = @\file_get_contents($file);
                $parsed = \json_decode($content ?: '', true);
                if (\is_array($parsed)) {
                    $data = $parsed;
                }
            }

            $newData = $modifier($data);
            if (!\is_array($newData)) {
                return false;
            }

            $json = \json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            $tempFile = $file . '.tmp.' . \getmypid();
            if (@\file_put_contents($tempFile, $json) === false) {
                return false;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                @\unlink($file);
            }

            $success = @\rename($tempFile, $file);
            if (!$success) {
                @\unlink($tempFile);
            }
            return $success;
        } finally {
            \flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }

    /**
     * 清理陈旧临时文件
     */
    private static function cleanupStaleTempFiles(string $file): void
    {
        $dir = \dirname($file);
        $basename = \basename($file);
        $pattern = $dir . DIRECTORY_SEPARATOR . $basename . '.tmp.*';

        $tmpFiles = @\glob($pattern);
        if ($tmpFiles === false || $tmpFiles === []) {
            return;
        }

        $now = \time();
        $staleThreshold = 60;

        foreach ($tmpFiles as $tmpFile) {
            $mtime = @\filemtime($tmpFile);
            if ($mtime === false) {
                continue;
            }

            if ($now - $mtime > $staleThreshold) {
                @\unlink($tmpFile);
                continue;
            }

            if (\preg_match('/\.tmp\.(\d+)$/', $tmpFile, $matches)) {
                $pid = (int) $matches[1];
                if ($pid > 0 && !Processer::processExists($pid)) {
                    @\unlink($tmpFile);
                }
            }
        }
    }
}
