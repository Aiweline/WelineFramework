<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Cache\Service\SingleFlightCoordinator;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Model\WlsPanelProject;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class WlsPanelLifecycleService
{
    private const CURRENT_PROJECT_KEY = 'current';
    private const REGISTERED_PROJECT_PREFIX = 'registered:';
    private const OPERATION_LOCK_TTL_SECONDS = 90;

    public function __construct(
        private readonly WlsPanelProjectRegistryService $projectRegistry,
        private readonly ServerInstanceManager $instanceManager,
        private readonly IpcControlGateway $ipcControlGateway,
        private readonly SingleFlightCoordinator $singleFlight,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $projectCards
     * @return array<string, array<string, mixed>>
     */
    public function getTargets(array $projectCards): array
    {
        $targets = [];
        foreach ($projectCards as $index => $projectCard) {
            if (!\is_array($projectCard)) {
                continue;
            }

            $projectKey = $this->projectKeyFromCard($projectCard, (int)$index);
            $targets[$projectKey] = $this->status($projectKey);
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $projectKey): array
    {
        $projectKey = $this->normalizeProjectKey($projectKey);
        try {
            $target = $this->resolveTarget($projectKey);
        } catch (\Throwable $throwable) {
            return $this->unavailableStatus(
                $projectKey,
                '',
                $throwable->getMessage()
            );
        }

        if (empty($target['available'])) {
            return $this->unavailableStatus(
                $projectKey,
                (string)($target['project_name'] ?? ''),
                (string)($target['message'] ?? __('当前项目不支持从此面板控制 WLS。')),
                $target
            );
        }

        $instanceName = (string)$target['instance'];
        $statusResult = $this->ipcControlGateway->getStatus($instanceName, 3.0);
        if (empty($statusResult['success'])) {
            return [
                'success' => true,
                'project_key' => $projectKey,
                'project_name' => (string)$target['project_name'],
                'instance' => $instanceName,
                'available' => true,
                'running' => false,
                'ready' => false,
                'busy' => false,
                'state' => 'offline',
                'state_label' => (string)__('不可用'),
                'epoch' => 0,
                'master_pid' => $this->masterPid($instanceName),
                'ready_services' => 0,
                'total_services' => 0,
                'ready_workers' => 0,
                'total_workers' => 0,
                'worker_generation' => 0,
                'worker_revision' => '',
                'message' => (string)($statusResult['message'] ?? __('WLS Master 控制通道不可用。')),
                'panel_url' => (string)($target['panel_url'] ?? ''),
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
        }

        $raw = \is_array($statusResult['data'] ?? null) ? $statusResult['data'] : [];
        $serviceCounts = $this->serviceCounts((array)($raw['services'] ?? []));
        $running = !empty($raw['running']);
        $busy = !empty($raw['shutting_down']) || !empty($raw['rolling_restart_in_progress']);
        $ready = $running
            && !$busy
            && $serviceCounts['total_workers'] > 0
            && $serviceCounts['ready_workers'] === $serviceCounts['total_workers']
            && $serviceCounts['ready_services'] === $serviceCounts['total_services'];
        $state = $busy ? 'reloading' : ($ready ? 'ready' : ($running ? 'degraded' : 'offline'));

        return [
            'success' => true,
            'project_key' => $projectKey,
            'project_name' => (string)$target['project_name'],
            'instance' => $instanceName,
            'available' => true,
            'running' => $running,
            'ready' => $ready,
            'busy' => $busy,
            'state' => $state,
            'state_label' => match ($state) {
                'ready' => (string)__('Ready'),
                'reloading' => (string)__('重载中'),
                'degraded' => (string)__('恢复中'),
                default => (string)__('不可用'),
            },
            'epoch' => (int)($raw['epoch'] ?? 0),
            'master_pid' => (int)($raw['master_pid'] ?? $this->masterPid($instanceName)),
            'ready_services' => $serviceCounts['ready_services'],
            'total_services' => $serviceCounts['total_services'],
            'ready_workers' => $serviceCounts['ready_workers'],
            'total_workers' => $serviceCounts['total_workers'],
            'worker_generation' => $serviceCounts['worker_generation'],
            'worker_revision' => $serviceCounts['worker_revision'],
            'message' => $ready
                ? (string)__('WLS 实例已就绪。')
                : ($busy ? (string)__('WLS 正在重载，请等待恢复到 Ready。') : (string)__('WLS 尚未完全就绪。')),
            'panel_url' => (string)($target['panel_url'] ?? ''),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reload(string $projectKey): array
    {
        return $this->operate($projectKey, 'reload');
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(string $projectKey, string $confirmedProjectName): array
    {
        return $this->operate($projectKey, 'restart', $confirmedProjectName);
    }

    /**
     * @return array<string, mixed>
     */
    private function operate(string $projectKey, string $action, string $confirmedProjectName = ''): array
    {
        $projectKey = $this->normalizeProjectKey($projectKey);
        $before = $this->status($projectKey);

        if (empty($before['available'])) {
            return $this->operationFailure($action, $before, (string)($before['message'] ?? __('该项目不可控制。')));
        }
        if (empty($before['running'])) {
            return $this->operationFailure($action, $before, (string)__('WLS Master 未运行，无法提交操作。'));
        }
        if (!empty($before['busy'])) {
            return $this->operationFailure($action, $before, (string)__('该实例已有生命周期操作正在执行。'));
        }

        $projectName = (string)($before['project_name'] ?? '');
        if ($action === 'restart' && \trim($confirmedProjectName) !== $projectName) {
            return $this->operationFailure($action, $before, (string)__('项目名称确认不匹配，已取消重启。'));
        }

        $instanceName = (string)$before['instance'];
        $lockKey = 'wls-panel-lifecycle:' . \hash('sha256', $instanceName);
        $lockToken = $this->singleFlight->acquire($lockKey, 0, self::OPERATION_LOCK_TTL_SECONDS);
        if ($lockToken === null) {
            return $this->operationFailure($action, $before, (string)__('该实例的生命周期操作正在提交，请稍后重试。'));
        }

        try {
            $latest = $this->status($projectKey);
            if (!empty($latest['busy'])) {
                return $this->operationFailure($action, $latest, (string)__('该实例已有生命周期操作正在执行。'));
            }

            $reloadType = $action === 'restart'
                ? ControlMessage::RELOAD_TYPE_FORCE
                : ControlMessage::RELOAD_TYPE_CODE;
            $result = $this->ipcControlGateway->reloadAsync($instanceName, $reloadType, 8.0);
            $success = !empty($result['success']);
            $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
            $operationId = \trim((string)($data['operation_id'] ?? $data['msg_id'] ?? $data['request_id'] ?? ''));

            return [
                'success' => $success,
                'message' => $success
                    ? ($action === 'restart'
                        ? (string)__('WLS 重启已提交；Master 保持在线，正在重建全部工作进程。')
                        : (string)__('WLS 重载已提交，正在滚动更新工作进程。'))
                    : (string)($result['message'] ?? __('WLS 生命周期操作提交失败。')),
                'action' => $action,
                'operation_id' => $operationId,
                'submitted_at' => \date('Y-m-d H:i:s'),
                'baseline_epoch' => (int)($latest['epoch'] ?? 0),
                'baseline_worker_generation' => (int)($latest['worker_generation'] ?? 0),
                'baseline_worker_revision' => (string)($latest['worker_revision'] ?? ''),
                'status' => $latest,
            ];
        } finally {
            $this->singleFlight->release($lockKey, $lockToken);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTarget(string $projectKey): array
    {
        if ($projectKey === self::CURRENT_PROJECT_KEY) {
            return $this->localTarget(
                $projectKey,
                (string)__('Current Project'),
                \defined('BP') ? BP : \dirname(__DIR__, 5),
                '',
                0
            );
        }

        if (!\str_starts_with($projectKey, self::REGISTERED_PROJECT_PREFIX)) {
            throw new \InvalidArgumentException((string)__('WLS 项目标识无效。'));
        }

        $projectId = (int)\substr($projectKey, \strlen(self::REGISTERED_PROJECT_PREFIX));
        if ($projectId <= 0) {
            throw new \InvalidArgumentException((string)__('WLS 项目标识无效。'));
        }

        $project = $this->projectRegistry->loadProject($projectId);
        if (!$project->getData(WlsPanelProject::schema_fields_ID)) {
            throw new \InvalidArgumentException((string)__('托管项目不存在。'));
        }

        $projectName = (string)$project->getData(WlsPanelProject::schema_fields_NAME);
        $panelUrl = (string)$project->getData(WlsPanelProject::schema_fields_PANEL_URL);
        if ((string)$project->getData(WlsPanelProject::schema_fields_STATUS) !== WlsPanelProject::STATUS_ACTIVE) {
            return $this->remoteTarget(
                $projectKey,
                $projectName,
                $panelUrl,
                (string)__('托管项目已停用，不能执行 WLS 生命周期操作。')
            );
        }

        $projectPath = (string)$project->getData(WlsPanelProject::schema_fields_PROJECT_PATH);
        if (!$this->isCurrentProjectPath($projectPath)) {
            return $this->remoteTarget(
                $projectKey,
                $projectName,
                $panelUrl,
                (string)__('该项目使用独立控制面，请进入它自己的 WLS 面板执行重载或重启。')
            );
        }

        return $this->localTarget(
            $projectKey,
            $projectName,
            $projectPath,
            $panelUrl,
            (int)$project->getData(WlsPanelProject::schema_fields_BACKEND_PORT)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function localTarget(
        string $projectKey,
        string $projectName,
        string $projectPath,
        string $panelUrl,
        int $preferredPort
    ): array {
        $instance = $this->resolveLocalInstance($preferredPort);
        if ($instance === '') {
            return [
                'project_key' => $projectKey,
                'project_name' => $projectName,
                'project_path' => $projectPath,
                'panel_url' => $panelUrl,
                'instance' => '',
                'available' => false,
                'message' => (string)__('无法唯一识别该项目的 WLS 实例，请先确认实例正在运行。'),
            ];
        }

        return [
            'project_key' => $projectKey,
            'project_name' => $projectName,
            'project_path' => $projectPath,
            'panel_url' => $panelUrl,
            'instance' => $instance,
            'available' => true,
            'message' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteTarget(string $projectKey, string $projectName, string $panelUrl, string $message): array
    {
        return [
            'project_key' => $projectKey,
            'project_name' => $projectName,
            'project_path' => '',
            'panel_url' => $panelUrl,
            'instance' => '',
            'available' => false,
            'message' => $message,
        ];
    }

    private function resolveLocalInstance(int $preferredPort = 0): string
    {
        foreach ($this->runtimeInstanceCandidates() as $candidate) {
            $resolved = $this->instanceManager->resolvePersistedInstanceName($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $instances = $this->instanceManager->getAllPersistedInstanceInfo();
        if ($preferredPort > 0) {
            $portMatches = [];
            foreach ($instances as $name => $info) {
                if ($info instanceof ServerInstanceInfo && $info->port === $preferredPort) {
                    $portMatches[] = (string)$name;
                }
            }
            if (\count($portMatches) === 1) {
                return $portMatches[0];
            }
        }

        $running = [];
        foreach ($instances as $name => $info) {
            if (!$info instanceof ServerInstanceInfo || $info->controlPort <= 0 || $info->masterPid <= 0) {
                continue;
            }
            if ($info->isMasterRunning()) {
                $running[] = (string)$name;
            }
        }

        return \count($running) === 1 ? $running[0] : '';
    }

    /** @return array<int, string> */
    private function runtimeInstanceCandidates(): array
    {
        $candidates = [
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            \getenv('WLS_INSTANCE') ?: null,
            \getenv('WLS_INSTANCE_NAME') ?: null,
            \defined('WLS_INSTANCE') ? \constant('WLS_INSTANCE') : null,
            \defined('WLS_INSTANCE_NAME') ? \constant('WLS_INSTANCE_NAME') : null,
        ];

        $normalized = [];
        foreach ($candidates as $candidate) {
            $value = \trim((string)$candidate);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return \array_values($normalized);
    }

    private function isCurrentProjectPath(string $projectPath): bool
    {
        $projectPath = \trim($projectPath);
        if ($projectPath === '') {
            return false;
        }

        $currentPath = \defined('BP') ? BP : \dirname(__DIR__, 5);
        $left = \realpath($projectPath);
        $right = \realpath($currentPath);
        if (!\is_string($left) || !\is_string($right)) {
            return false;
        }

        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }

        return $left === $right;
    }

    /**
     * @param array<string, mixed> $projectCard
     */
    private function projectKeyFromCard(array $projectCard, int $index): string
    {
        $type = \trim((string)($projectCard['type'] ?? ''));
        $projectId = (int)($projectCard['id'] ?? 0);
        if ($type === 'current') {
            return self::CURRENT_PROJECT_KEY;
        }
        if ($type === 'registered' && $projectId > 0) {
            return self::REGISTERED_PROJECT_PREFIX . $projectId;
        }

        return 'unmanaged:' . $index;
    }

    private function normalizeProjectKey(string $projectKey): string
    {
        $projectKey = \trim($projectKey);
        if ($projectKey === '' || \strlen($projectKey) > 80) {
            return 'invalid';
        }

        return $projectKey;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function unavailableStatus(
        string $projectKey,
        string $projectName,
        string $message,
        array $target = []
    ): array {
        return [
            'success' => true,
            'project_key' => $projectKey,
            'project_name' => $projectName,
            'instance' => '',
            'available' => false,
            'running' => false,
            'ready' => false,
            'busy' => false,
            'state' => 'unavailable',
            'state_label' => (string)__('不可控制'),
            'epoch' => 0,
            'master_pid' => 0,
            'ready_services' => 0,
            'total_services' => 0,
            'ready_workers' => 0,
            'total_workers' => 0,
            'worker_generation' => 0,
            'worker_revision' => '',
            'message' => $message,
            'panel_url' => (string)($target['panel_url'] ?? ''),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function operationFailure(string $action, array $status, string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'action' => $action,
            'operation_id' => '',
            'submitted_at' => '',
            'baseline_epoch' => (int)($status['epoch'] ?? 0),
            'baseline_worker_generation' => (int)($status['worker_generation'] ?? 0),
            'baseline_worker_revision' => (string)($status['worker_revision'] ?? ''),
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $services
     * @return array{ready_services:int,total_services:int,ready_workers:int,total_workers:int,worker_generation:int,worker_revision:string}
     */
    private function serviceCounts(array $services): array
    {
        $counts = [
            'ready_services' => 0,
            'total_services' => 0,
            'ready_workers' => 0,
            'total_workers' => 0,
            'worker_generation' => 0,
            'worker_revision' => '',
        ];
        $workerRevisionParts = [];
        foreach ($services as $role => $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }
            foreach ((array)($roleData['instances'] ?? []) as $instance) {
                if (!\is_array($instance)) {
                    continue;
                }
                $counts['total_services']++;
                $isReady = (string)($instance['state'] ?? '') === 'ready';
                if ($isReady) {
                    $counts['ready_services']++;
                }
                if ((string)$role === 'worker') {
                    $counts['total_workers']++;
                    if ($isReady) {
                        $counts['ready_workers']++;
                    }
                    $metadata = \is_array($instance['metadata'] ?? null) ? $instance['metadata'] : [];
                    $generation = (int)($metadata['generation'] ?? $instance['generation'] ?? 0);
                    $counts['worker_generation'] = \max($counts['worker_generation'], $generation);
                    $workerRevisionParts[] = \implode(':', [
                        (string)($instance['instance_id'] ?? ''),
                        (string)($instance['pid'] ?? ''),
                        (string)($instance['launch_id'] ?? ''),
                        (string)$generation,
                    ]);
                }
            }
        }

        \sort($workerRevisionParts, SORT_STRING);
        $counts['worker_revision'] = $workerRevisionParts === []
            ? ''
            : \hash('sha256', \implode('|', $workerRevisionParts));

        return $counts;
    }

    private function masterPid(string $instanceName): int
    {
        try {
            return (int)($this->instanceManager->getPersistedInstanceInfo($instanceName)?->masterPid ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
