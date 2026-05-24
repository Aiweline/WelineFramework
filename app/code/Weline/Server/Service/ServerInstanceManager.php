<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Control\IpcControlGateway;
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
 * - 开闭原则：运行拓扑由 Master registry 扩展，本类只保存 Master endpoint/config
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
        return $this->getInstanceInfoUsingIpcTimeout($name, $validateStale, 1.5);
    }

    public function getInstanceInfoWithIpcTimeout(string $name, bool $validateStale, float $ipcTimeout): ?ServerInstanceInfo
    {
        return $this->getInstanceInfoUsingIpcTimeout($name, $validateStale, $ipcTimeout);
    }

    private function getInstanceInfoUsingIpcTimeout(string $name, bool $validateStale, float $ipcTimeout): ?ServerInstanceInfo
    {
        $rawData = $this->getRawInstanceData($name);
        if ($rawData === null) {
            return null;
        }

        $info = $this->buildInstanceInfo($name, $rawData, $ipcTimeout);
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
     * 清理当前没有运行中 Master/服务的 endpoint 记录。
     *
     * server:clean 的语义是“没运行就删”，不依赖 lifecycle_state 必须先被标记为 stopped。
     *
     * @return string[] 已清理的实例名称列表
     */
    public function cleanupInactiveInstances(): array
    {
        $cleanedNames = [];
        $touchedPids = [];

        foreach ($this->listRawInstanceNames() as $name) {
            $rawData = $this->getRawInstanceData($name);
            if ($rawData === null) {
                continue;
            }

            if ($this->isStartLockHeld($name)) {
                continue;
            }

            if (!$this->shouldPurgeStoppedInstanceRecord($rawData)) {
                $status = $this->getMasterIpcStatusResult($name, 0.5);
                if ($status['success'] && (bool)($status['data']['running'] ?? false)) {
                    continue;
                }
            }

            foreach ($this->collectTrackedPids($name, $rawData) as $pid) {
                $touchedPids[$pid] = true;
            }
            $this->purgeInactiveInstanceArtifacts($name, $rawData);
            $cleanedNames[] = $name;
        }

        if ($touchedPids !== []) {
            Processer::cleanupStalePidFilesForPids(\array_map('intval', \array_keys($touchedPids)));
        }

        return $cleanedNames;
    }

    private function shouldPurgeStoppedInstanceRecord(array $rawData): bool
    {
        $lifecycleState = (string)($rawData['lifecycle_state'] ?? $rawData['startup_phase'] ?? '');
        return \in_array($lifecycleState, ['stopped', 'stale_cleanup', 'master_exited'], true);
    }

    /**
     * 人工清场：删除停机实例的全部本地记录文件。
     */
    private function purgeInactiveInstanceArtifacts(string $name, array $rawData): void
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

        $instanceFile = $this->getInstanceFile($name);
        if (\is_file($instanceFile)) {
            @\unlink($instanceFile);
        }

        $instanceLockFile = $instanceFile . '.lock';
        if (\is_file($instanceLockFile)) {
            @\unlink($instanceLockFile);
        }

        $resurrectLockFile = $this->getInstanceDir() . $name . '.resurrect.lock';
        if (\is_file($resurrectLockFile)) {
            @\unlink($resurrectLockFile);
        }
    }

    /**
     * 根据端口查找正在运行的实例
     */
    public function findRunningInstanceNameByPort(int $port): ?string
    {
        foreach ($this->getAllInstanceInfo() as $name => $info) {
            $status = $this->getMasterIpcStatus($name);
            if ($status === null || !((bool)($status['running'] ?? false))) {
                continue;
            }

            if ($info->port === $port) {
                return $name;
            }

            if ($info->httpRedirectPort > 0 && $info->httpRedirectPort === $port) {
                return $name;
            }

            if (\in_array($port, $this->collectRunningPortsFromServices((array)($status['services'] ?? []), 'worker'), true)) {
                return $name;
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
        // Runtime service topology belongs to Master registry and IPC status only.
        // The instance file is only a Master endpoint/config record.
    }

    private function mergeInstanceRecordData(array $existing, array $data): array
    {
        $merged = $this->filterEndpointRecord(\array_merge($existing, $data));
        $merged['lifecycle_state'] = (string)($data['startup_phase'] ?? 'starting');

        return $merged;
    }

    /**
     * Instance JSON is limited to startup configuration and Master endpoint
     * metadata. Worker/dispatcher/service topology is excluded by default.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterEndpointRecord(array $data): array
    {
        $allowedFields = [
            'schema_version',
            'name',
            'instance_name',
            'pid',
            'launcher_pid',
            'master_pid',
            'master_enabled',
            'master_started_at',
            'master_mode',
            'orchestrator_mode',
            'control_plane_mode',
            'supervisor_enabled',
            'supervisor_channel',
            'supervisor_endpoint',
            'control_port',
            'control_token',
            'control_token_created_at',
            'epoch',
            'master_epoch',
            'host',
            'public_host',
            'port',
            'main_port',
            'count',
            'daemon',
            'ssl_enabled',
            'ssl_cert',
            'ssl_key',
            'dispatcher_enabled',
            'dispatcher_port',
            'worker_port',
            'worker_base_port',
            'worker_memory_limit',
            'dispatcher_memory_limit',
            'session_server_port',
            'session_server_token_file_name',
            'memory_server_port',
            'memory_server_token_file_name',
            'shared_state',
            'orchestrator_runtime_options',
            'http_redirect_port',
            'started_by',
            'started_at',
            'started_timestamp',
            'php_version',
            'os',
            'window_mode',
            'frontend',
            'enable_log',
            'runtime_state',
            'last_verified_at',
            'startup_phase',
            'lifecycle_state',
            'stopped_reason',
            'stopped_at',
            'stopped_timestamp',
            'server_ready_at',
            'server_ready_service_count',
            'startup_event_seq',
            'startup_events',
            'startup_failure_reason',
            'startup_failure_at',
            'startup_failure_timestamp',
            'startup_failure_pending',
            'master_exited_pid',
            'retained_pids',
            'retained_pid_count',
            'retained_at',
            'retained_timestamp',
            'slot_generations',
            'slot_generations_updated_at',
            'updated_at',
        ];

        $filtered = [];
        foreach ($allowedFields as $field) {
            if (\array_key_exists($field, $data)) {
                $filtered[$field] = $data[$field];
            }
        }

        return $filtered;
    }

    public function updateMasterPid(string $instanceName, int $masterPid): void
    {
        $file = $this->getInstanceFile($instanceName);
        if (!\is_file($file)) {
            return;
        }
        $this->atomicUpdateJson($file, function (array $data) use ($masterPid): array {
            $data['master_pid'] = $masterPid;
            $data['pid'] = $masterPid;
            return $this->filterEndpointRecord($data);
        });
    }

    /**
     * 注册单个服务实例（子进程调用或 Orchestrator 单个更新）
     */
    public function registerService(string $instanceName, ServiceInfo $service): void
    {
        // Child processes must report to Master IPC. They no longer mutate the
        // instance endpoint file.
    }

    public function deleteInstance(string $name): bool
    {
        $file = $this->getInstanceFile($name);
        if (\is_file($file)) {
            return $this->markInstanceRecordStopped($file, 'deleted');
        }
        return true;
    }

    private function markInstanceRecordStopped(string $file, string $reason): bool
    {
        if (!\is_file($file)) {
            return true;
        }

        $now = \time();
        $at = \date('Y-m-d H:i:s', $now);

        return $this->atomicUpdateJson($file, function (array $data) use ($reason, $now, $at): array {
            $data['pid'] = 0;
            $data['master_pid'] = 0;
            $data['master_enabled'] = false;
            $data['startup_phase'] = 'stopped';
            $data['lifecycle_state'] = 'stopped';
            $data['stopped_reason'] = $reason;
            $data['stopped_at'] = $at;
            $data['stopped_timestamp'] = $now;
            $data['updated_at'] = $now;

            return $this->filterEndpointRecord($data);
        });
    }

    /**
     * Master 退出后整理实例记录：
     * - 清空 master_pid / pid 等主控信息
     * - 若仍有受管子进程存活，则保留实例文件，供 stop/status 继续恢复控制
     * - 若已无存活进程，则清理实例记录及其痕迹
     *
     * @return bool true 表示实例记录被保留；false 表示实例已不存在或已被清理
     */
    public function finalizeAfterMasterExit(string $name, int $masterPid): bool
    {
        $file = $this->getInstanceFile($name);
        if (!\is_file($file)) {
            return false;
        }

        $exitTimestamp = \time();
        $exitAt = \date('Y-m-d H:i:s', $exitTimestamp);

        $this->atomicUpdateJson($file, function (array $data) use ($masterPid, $exitTimestamp, $exitAt): array {
            $data['pid'] = 0;
            $data['master_pid'] = 0;
            $data['master_enabled'] = false;
            $data['startup_phase'] = 'master_exited';
            $data['lifecycle_state'] = 'master_exited';
            $data['master_exited_pid'] = $masterPid;
            $data['master_exited_at'] = $exitAt;
            $data['master_exited_timestamp'] = $exitTimestamp;
            $data['updated_at'] = $exitTimestamp;

            return $this->filterEndpointRecord($data);
        });

        $rawData = $this->getRawInstanceData($name);
        if ($rawData === null) {
            return false;
        }

        $runningPids = $this->collectRunningTrackedPids($name, $rawData, [$masterPid]);
        if ($runningPids === []) {
            $this->cleanupStaleInstanceArtifacts($name, $rawData);
            return false;
        }

        $this->atomicUpdateJson($file, function (array $data) use ($runningPids, $exitTimestamp, $exitAt): array {
            $data['lifecycle_state'] = 'master_exited_children_retained';
            $data['retained_pids'] = $runningPids;
            $data['retained_pid_count'] = \count($runningPids);
            $data['retained_at'] = $exitAt;
            $data['retained_timestamp'] = $exitTimestamp;
            $data['updated_at'] = $exitTimestamp;

            return $this->filterEndpointRecord($data);
        });

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
        if (\in_array((string)($rawData['lifecycle_state'] ?? ''), ['stopped', 'stale_cleanup', 'master_exited'], true)
            && !$this->hasTrackedRunningProcess($name, $rawData, $info)) {
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

        return $this->collectRunningTrackedPids($name, $rawData) !== [];
    }

    /**
     * 收集当前仍存活的受管 PID。
     *
     * @param int[] $ignoredPids
     * @return int[]
     */
    private function collectRunningTrackedPids(string $name, array $rawData, array $ignoredPids = []): array
    {
        $ignored = [];
        foreach ($ignoredPids as $ignoredPid) {
            $ignoredPid = (int) $ignoredPid;
            if ($ignoredPid > 0) {
                $ignored[$ignoredPid] = true;
            }
        }

        $candidatePids = [];
        foreach ($this->collectTrackedPids($name, $rawData) as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || isset($ignored[$pid])) {
                continue;
            }

            $candidatePids[$pid] = true;
        }

        if ($candidatePids === []) {
            return [];
        }

        $processInfo = Processer::batchGetProcessInfo(\array_map('intval', \array_keys($candidatePids)));
        $running = [];
        foreach (\array_keys($candidatePids) as $pid) {
            $pid = (int) $pid;
            $info = \is_array($processInfo[$pid] ?? null) ? $processInfo[$pid] : [];
            if (!(bool)($info['exists'] ?? false) || (bool)($info['is_zombie'] ?? false)) {
                continue;
            }
            if ($this->isTrackedProcessIdentityForInstance($name, $rawData, $pid)) {
                $running[$pid] = true;
            }
        }

        return \array_map('intval', \array_keys($running));
    }

    private function isTrackedProcessIdentityForInstance(string $name, array $rawData, int $pid): bool
    {
        $allowedPnames = [];
        $allowedProcessNames = [];
        foreach ($this->collectManagedProcessNames($name, $rawData) as $pname) {
            $allowedPnames[$pname] = true;
            try {
                $processName = \str_starts_with($pname, '--name=')
                    ? \substr($pname, 7)
                    : Processer::getTaskName($pname);
            } catch (\Throwable) {
                $processName = '';
            }
            if ($processName !== '') {
                $allowedProcessNames[$processName] = true;
            }
        }

        $record = Processer::getProcessRecordByPid($pid);
        $indexedPname = Processer::getNameByPid($pid);
        $recordPname = (string)($record['pname'] ?? '');
        foreach ([$recordPname, $indexedPname] as $pname) {
            if ($pname !== '' && isset($allowedPnames[$pname])) {
                return true;
            }
            if (\str_starts_with($pname, '--name=')) {
                $processName = \substr($pname, 7);
                if ($processName !== '' && isset($allowedProcessNames[$processName])) {
                    return true;
                }
            }
        }

        $recordProcessName = (string)($record['process_name'] ?? $record['task_name'] ?? '');
        return $recordProcessName !== '' && isset($allowedProcessNames[$recordProcessName]);
    }

    /**
     * 收集实例关联的受管 PID
     *
     * @return int[]
     */
    private function collectTrackedPids(string $name, array $rawData): array
    {
        $pids = [];

        foreach (['pid', 'launcher_pid', 'master_pid'] as $field) {
            $pid = (int) ($rawData[$field] ?? 0);
            if ($pid > 0) {
                $pids[$pid] = true;
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
            '--name=' . MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name),
            '--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', $name),
            '--name=' . MasterProcess::buildScopedProcessName('weline-wls-memory', $name),
            '--name=' . MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name),
        ];

        $count = (int) ($rawData['count'] ?? 0);
        for ($i = 1; $i <= $count; $i++) {
            $names[] = '--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', $name, $i);
        }

        $maintenancePrefix = MasterProcess::buildScopedProcessName('weline-wls-maintenance', $name) . '-';
        foreach (Processer::getProcessNamesByPrefix($maintenancePrefix) as $processName) {
            $names[] = $processName;
        }

        return \array_values(\array_unique(\array_filter($names)));
    }

    /**
     * 清理陈旧实例留下的文件痕迹
     */
    private function cleanupStaleInstanceArtifacts(string $name, array $rawData): void
    {
        $trackedPids = $this->collectTrackedPids($name, $rawData);
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

        $this->markInstanceRecordStopped($this->getInstanceFile($name), 'stale_cleanup');
        Processer::cleanupStalePidFilesForPids($trackedPids);
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
    private function buildInstanceInfo(string $name, array $rawData, float $ipcTimeout = 1.5): ServerInstanceInfo
    {
        $runtimeData = $rawData;
        $ipcStatus = $ipcTimeout > 0.0 ? $this->getMasterIpcStatus($name, $ipcTimeout) : null;
        $httpRedirectPort = $this->resolveHttpRedirectPort($runtimeData);

        return new ServerInstanceInfo(
            name: $name,
            masterPid: (int) ($runtimeData['master_pid'] ?? 0),
            controlPort: (int) ($runtimeData['control_port'] ?? 0),
            host: (string) ($runtimeData['host'] ?? '127.0.0.1'),
            port: (int) ($runtimeData['port'] ?? 0),
            sslEnabled: (bool) ($runtimeData['ssl_enabled'] ?? false),
            dispatcherEnabled: (bool) ($runtimeData['dispatcher_enabled'] ?? false),
            workerCount: (int) ($runtimeData['count'] ?? 0),
            workerBasePort: (int) ($runtimeData['worker_port'] ?? $runtimeData['port'] ?? 0),
            httpRedirectPort: $httpRedirectPort,
            startedAt: (string) ($runtimeData['started_at'] ?? ''),
            startedTimestamp: (int) ($runtimeData['started_timestamp'] ?? 0),
            services: $ipcStatus === null ? [] : $this->buildServiceInfoListFromIpcStatus($ipcStatus),
            controlToken: (string) ($runtimeData['control_token'] ?? ''),
        );
    }

    /**
     * @return list<ServiceInfo>
     */
    private function buildServiceInfoListFromIpcStatus(array $status): array
    {
        $services = [];
        $roles = \is_array($status['services'] ?? null) ? $status['services'] : [];

        foreach ($roles as $role => $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }

            $instances = \is_array($roleData['instances'] ?? null) ? $roleData['instances'] : [];
            $displayName = (string)($roleData['display_name'] ?? self::ROLE_DISPLAY_NAMES[(string)$role] ?? (string)$role);
            $priority = (int)($roleData['priority'] ?? 99);

            foreach ($instances as $instance) {
                if (!\is_array($instance)) {
                    continue;
                }

                $instance['role'] = (string)($instance['role'] ?? $role);
                $instance['display_name'] = (string)($instance['display_name'] ?? $displayName);
                $instance['priority'] = (int)($instance['priority'] ?? $priority);
                $services[] = ServiceInfo::fromArray($instance);
            }
        }

        return $services;
    }

    private function resolveHttpRedirectPort(array $rawData): int
    {
        $httpRedirectPort = (int) ($rawData['http_redirect_port'] ?? 0);
        if ($httpRedirectPort > 0) {
            return $httpRedirectPort;
        }

        $mainPort = (int) ($rawData['port'] ?? 0);
        $sslEnabled = (bool) ($rawData['ssl_enabled'] ?? $mainPort === 443);
        if ($sslEnabled && $mainPort === 443) {
            return 80;
        }

        return 0;
    }

    /**
     * 原子更新 JSON 文件
     */
    private function atomicUpdateJson(string $file, callable $updater): bool
    {
        return self::updateJsonFileAtomically($file, $updater);
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
        $status = $this->getMasterIpcStatus($name);
        return $status !== null && (bool)($status['running'] ?? false);
    }

    /**
     * Check whether an instance can currently receive Master IPC control commands.
     */
    public function isInstanceIpcControllable(string $name): bool
    {
        $info = $this->getPersistedInstanceInfo($name);
        $controlPort = $info?->controlPort ?? 0;

        if ($controlPort <= 0) {
            return false;
        }

        // CLI 启动路径下，instance file 记录的 master_pid 可能对应最外层
        // `php bin/w server:start` 进程，而不是带 `--name=weline-wls-master-*`
        // 的受管子进程。此时严格的 Master 身份校验会误判，但真正决定
        // IPC 可控性的仍是控制端口是否可达。
        return Processer::isPortInUse($controlPort);
    }

    /**
     * 统计实例中真正运行的 Worker 数量
     *
     * 使用实时 IPC 校验，避免 endpoint 停止态或陈旧文件被误判为「有 Worker」，
     * 导致 server:reload 等命令跳过发送控制指令。
     */
    public function countRunningWorkers(string $name): int
    {
        $status = $this->getMasterIpcStatus($name);
        if ($status === null) {
            return 0;
        }

        return $this->countRunningRoleFromIpcStatus($status, 'worker');
    }

    /**
     * 是否有任意运行中的 WLS Worker
     *
     * 仍走持久化快路径（避免 Observer/配置刷新场景反复触发系统进程探测）。
     * 需要精确进程存活判断时请用 {@see countRunningWorkers}（按实例、实时校验）。
     */
    public function hasRunningWorkers(): bool
    {
        foreach ($this->listPersistedInstanceNames() as $name) {
            $status = $this->getMasterIpcStatus($name);
            if ($status !== null && $this->countRunningRoleFromIpcStatus($status, 'worker') > 0) {
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
            $status = $this->getMasterIpcStatus($info->name);
            if ($status === null || !((bool)($status['running'] ?? false))) {
                continue;
            }

            $runtimeStats = $this->collectRuntimeStatsFromIpcStatus($status);
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

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function getMasterIpcStatusResult(string $name, float $timeout = 1.5): array
    {
        $status = (new IpcControlGateway())->getStatus($name, $timeout);
        return [
            'success' => (bool)($status['success'] ?? false),
            'message' => (string)($status['message'] ?? ''),
            'data' => \is_array($status['data'] ?? null) ? $status['data'] : [],
        ];
    }

    private function getMasterIpcStatus(string $name, float $timeout = 1.5): ?array
    {
        $status = $this->getMasterIpcStatusResult($name, $timeout);
        if (!$status['success'] || $status['data'] === []) {
            return null;
        }

        return $status['data'];
    }

    /**
     * @return array{instance_running: bool, workers: int, dispatchers: int, ports: int[]}
     */
    private function collectRuntimeStatsFromIpcStatus(array $status): array
    {
        $services = \is_array($status['services'] ?? null) ? $status['services'] : [];

        return [
            'instance_running' => (bool)($status['running'] ?? false),
            'workers' => $this->countRunningRoleFromServices($services, 'worker'),
            'desired_workers' => (int)($status['desired_state']['worker'] ?? 0),
            'dispatchers' => $this->countRunningRoleFromServices($services, 'dispatcher'),
            'ports' => $this->collectRunningPortsFromServices($services, 'worker'),
        ];
    }

    private function countRunningRoleFromIpcStatus(array $status, string $role): int
    {
        $services = \is_array($status['services'] ?? null) ? $status['services'] : [];
        return $this->countRunningRoleFromServices($services, $role);
    }

    private function countRunningRoleFromServices(array $services, string $role): int
    {
        $instances = \is_array($services[$role]['instances'] ?? null) ? $services[$role]['instances'] : [];
        $count = 0;
        foreach ($instances as $instance) {
            if (\is_array($instance) && $this->isIpcServiceStateRunning((string)($instance['state'] ?? ''))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return int[]
     */
    private function collectRunningPortsFromServices(array $services, string $role): array
    {
        $instances = \is_array($services[$role]['instances'] ?? null) ? $services[$role]['instances'] : [];
        $ports = [];
        foreach ($instances as $instance) {
            if (!\is_array($instance) || !$this->isIpcServiceStateRunning((string)($instance['state'] ?? ''))) {
                continue;
            }
            $port = (int)($instance['port'] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    private function isIpcServiceStateRunning(string $state): bool
    {
        return \in_array($state, [
            ServiceInstance::STATE_STARTING,
            ServiceInstance::STATE_REGISTERED,
            ServiceInstance::STATE_READY,
            ServiceInstance::STATE_DRAINING,
        ], true);
    }

    public function getPersistedInstanceInfo(string $name): ?ServerInstanceInfo
    {
        $rawData = $this->getRawInstanceData($name);
        if ($rawData === null) {
            return null;
        }

        return $this->buildInstanceInfo($name, $rawData, 0.0);
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
        unset($realtime);
        $status = $this->getMasterIpcStatus($info->name);
        if ($status !== null && (bool)($status['running'] ?? false)) {
            return $this->collectRuntimeStatsFromIpcStatus($status);
        }

        return [
            'instance_running' => false,
            'workers' => 0,
            'dispatchers' => 0,
            'ports' => [],
        ];
    }

    /**
     * @return array{instance_running: bool, workers: int, dispatchers: int, ports: int[], ipc_success: bool, ipc_message: string}
     */
    public function probeRuntimeStatsForInstance(ServerInstanceInfo $info, float $timeout = 6.0): array
    {
        $result = $this->getMasterIpcStatusResult($info->name, $timeout);
        if ($result['success'] && (bool)($result['data']['running'] ?? false)) {
            return $this->collectRuntimeStatsFromIpcStatus($result['data']) + [
                'ipc_success' => true,
                'ipc_message' => $result['message'],
            ];
        }

        return [
            'instance_running' => false,
            'workers' => 0,
            'dispatchers' => 0,
            'ports' => [],
            'ipc_success' => false,
            'ipc_message' => $result['message'],
        ];
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

        $this->atomicUpdateJson($file, fn(array $existing): array => $this->mergeInstanceRecordData($existing, $data));
    }

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
    public static function updateJsonFileAtomically(string $file, callable $modifier, int $timeout = 5): bool
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
