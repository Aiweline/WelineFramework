<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\TaskPoolInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * 任务池服务实现
 * 
 * 职责：全局任务看板，记录每个智能体的任务状态
 */
class TaskPoolService implements TaskPoolInterface
{
    private string $tasksFile;
    private string $configFile;
    private string $agentsDir;
    private array $taskPool = [];
    private array $config = [];
    private bool $loaded = false;
    private bool $verbose = false;

    public function __construct()
    {
        $this->agentsDir = BP . 'dev' . DS . 'ai' . DS . 'agents' . DS;
        $this->tasksFile = $this->agentsDir . 'tasks.json';
        $this->configFile = $this->agentsDir . 'config.json';

        PlatformHelper::ensureDirectoryExists($this->agentsDir);
        $this->loadConfig();
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 加载系统配置
     */
    private function loadConfig(): void
    {
        if (file_exists($this->configFile)) {
            $content = file_get_contents($this->configFile);
            $this->config = json_decode($content, true) ?: [];
        }
    }

    /**
     * 获取配置
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 获取 Agents 目录
     */
    public function getAgentsDir(): string
    {
        return $this->agentsDir;
    }

    /**
     * 加载任务池
     */
    public function load(): self
    {
        if (file_exists($this->tasksFile)) {
            $content = file_get_contents($this->tasksFile);
            $this->taskPool = json_decode($content, true) ?: $this->getDefaultPool();
        } else {
            $this->taskPool = $this->getDefaultPool();
        }
        $this->loaded = true;
        return $this;
    }

    /**
     * 保存任务池
     */
    public function save(): bool
    {
        $this->taskPool['updated_at'] = date('Y-m-d H:i:s');
        $content = json_encode($this->taskPool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($this->tasksFile, $content) !== false;
    }

    /**
     * 获取默认任务池结构
     */
    private function getDefaultPool(): array
    {
        return [
            'project' => basename(BP),
            'version' => '1.0',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'master' => [
                'status' => 'idle',
                'last_task' => null,
                'model' => 'deepseek',
            ],
            'agents' => [],
            'completed' => [],
            'failed' => [],
        ];
    }

    /**
     * 确保已加载
     */
    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    /**
     * 添加任务
     */
    public function addTask(
        string $agentId,
        string $targetFile,
        string $description,
        ?string $dependency = null,
        string $priority = 'normal'
    ): self {
        $this->ensureLoaded();

        $this->taskPool['agents'][$agentId] = [
            'id' => $agentId,
            'file' => $targetFile,
            'description' => $description,
            'status' => $dependency ? 'blocked' : 'todo',
            'dep' => $dependency,
            'priority' => $priority,
            'created_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'completed_at' => null,
            'error' => null,
            'retries' => 0,
        ];

        return $this;
    }

    /**
     * 批量添加任务
     */
    public function addTasks(array $tasks): self
    {
        foreach ($tasks as $task) {
            $this->addTask(
                $task['id'] ?? $task['agent_id'] ?? uniqid('task_'),
                $task['file'] ?? '',
                $task['description'] ?? '',
                $task['dep'] ?? $task['dependency'] ?? null,
                $task['priority'] ?? 'normal'
            );
        }
        return $this;
    }

    /**
     * 更新任务状态
     */
    public function updateStatus(string $agentId, string $status): self
    {
        $this->ensureLoaded();

        if (isset($this->taskPool['agents'][$agentId])) {
            $this->taskPool['agents'][$agentId]['status'] = $status;

            if ($status === 'running' && !$this->taskPool['agents'][$agentId]['started_at']) {
                $this->taskPool['agents'][$agentId]['started_at'] = date('Y-m-d H:i:s');
            }

            if ($status === 'done' || $status === 'completed') {
                $this->taskPool['agents'][$agentId]['completed_at'] = date('Y-m-d H:i:s');
                $this->taskPool['completed'][$agentId] = $this->taskPool['agents'][$agentId];
                unset($this->taskPool['agents'][$agentId]);
                $this->unblockDependents($agentId);
            }

            if ($status === 'failed') {
                $this->taskPool['failed'][$agentId] = $this->taskPool['agents'][$agentId];
            }
        }

        return $this;
    }

    /**
     * 解除依赖此任务的其他任务
     */
    private function unblockDependents(string $completedAgentId): void
    {
        foreach ($this->taskPool['agents'] as $agentId => &$task) {
            if ($task['dep'] === $completedAgentId && $task['status'] === 'blocked') {
                $task['status'] = 'todo';
            }
        }
    }

    /**
     * 获取就绪任务
     */
    public function getReadyTasks(): array
    {
        $this->ensureLoaded();
        return $this->getTasksByStatus('todo');
    }

    /**
     * 获取运行中的任务
     */
    public function getRunningTasks(): array
    {
        $this->ensureLoaded();
        return $this->getTasksByStatus('running');
    }

    /**
     * 按状态获取任务
     */
    public function getTasksByStatus(string $status): array
    {
        $this->ensureLoaded();
        $result = [];

        foreach ($this->taskPool['agents'] as $agentId => $task) {
            if ($task['status'] === $status) {
                $result[$agentId] = $task;
            }
        }

        return $result;
    }

    /**
     * 获取任务统计
     */
    public function getStats(): array
    {
        $this->ensureLoaded();

        $stats = [
            'total' => 0,
            'todo' => 0,
            'running' => 0,
            'blocked' => 0,
            'done' => count($this->taskPool['completed'] ?? []),
            'failed' => count($this->taskPool['failed'] ?? []),
        ];

        foreach ($this->taskPool['agents'] as $task) {
            $stats['total']++;
            $status = $task['status'] ?? 'todo';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        $stats['total'] += $stats['done'] + $stats['failed'];

        return $stats;
    }

    /**
     * 获取任务
     */
    public function getTask(string $agentId): ?array
    {
        $this->ensureLoaded();
        return $this->taskPool['agents'][$agentId] ?? null;
    }

    /**
     * 删除任务
     */
    public function removeTask(string $agentId): self
    {
        $this->ensureLoaded();
        unset($this->taskPool['agents'][$agentId]);
        return $this;
    }

    /**
     * 清空所有任务
     */
    public function clear(): self
    {
        $this->taskPool = $this->getDefaultPool();
        return $this;
    }

    /**
     * 获取 Master 状态
     */
    public function getMasterStatus(): array
    {
        $this->ensureLoaded();
        return $this->taskPool['master'] ?? [
            'status' => 'idle',
            'last_task' => null,
            'model' => 'deepseek',
        ];
    }

    /**
     * 获取任务池原始数据
     */
    public function getPool(): array
    {
        $this->ensureLoaded();
        return $this->taskPool;
    }
}
