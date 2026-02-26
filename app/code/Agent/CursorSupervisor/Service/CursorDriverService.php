<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Agent\CursorBase\Api\AgentDispatcherInterface;
use Agent\CursorBase\Api\TaskPoolInterface;
use Agent\CursorBase\Helper\FileTemplateHelper;
use Agent\CursorBase\Helper\PlatformHelper;
use Agent\CursorBase\Service\AgentDispatcher;
use Agent\CursorBase\Service\TaskPoolService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Cursor 驱动服务 (CLI Driver)
 * 
 * 职责：
 * 1. 从任务池获取可执行任务，派发给 Cursor
 * 2. 支持多实例并行执行
 * 3. 管理 Cursor 窗口生命周期
 * 
 * 重构说明：核心调度逻辑已迁移到 Agent_CursorBase 模块
 */
class CursorDriverService
{
    private ?TaskPoolInterface $taskPool = null;
    private ?AgentDispatcherInterface $dispatcher = null;

    private int $maxParallelAgents = 3;
    private array $activeInstances = [];
    private bool $autoTrigger = true;
    private bool $verbose = false;

    /**
     * 设置最大并行 Agent 数
     */
    public function setMaxParallelAgents(int $max): self
    {
        $this->maxParallelAgents = $max;
        return $this;
    }

    /**
     * 设置是否自动触发
     */
    public function setAutoTrigger(bool $autoTrigger): self
    {
        $this->autoTrigger = $autoTrigger;
        return $this;
    }

    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 获取任务池（使用 CursorBase 的实现）
     */
    private function getTaskPool(): TaskPoolInterface
    {
        if ($this->taskPool === null) {
            $this->taskPool = ObjectManager::getInstance(TaskPoolService::class);
        }
        return $this->taskPool;
    }

    /**
     * 获取调度器（使用 CursorBase 的实现）
     */
    private function getDispatcher(): AgentDispatcherInterface
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = ObjectManager::getInstance(AgentDispatcher::class);
            $this->dispatcher->setAutoTrigger($this->autoTrigger);
        }
        return $this->dispatcher;
    }

    /**
     * 驱动执行（主循环）
     */
    public function drive(): int
    {
        $this->getTaskPool()->load();

        $readyTasks = $this->getTaskPool()->getReadyTasks();
        $runningTasks = $this->getTaskPool()->getRunningTasks();

        $availableSlots = $this->maxParallelAgents - count($runningTasks);

        if ($availableSlots <= 0) {
            $this->log("已达最大并行数 ({$this->maxParallelAgents})，等待任务完成");
            return 0;
        }

        if (empty($readyTasks)) {
            $this->log("没有可执行的任务");
            return 0;
        }

        $dispatched = 0;

        foreach ($readyTasks as $agentId => $task) {
            if ($dispatched >= $availableSlots) {
                break;
            }

            $success = $this->dispatchTask($agentId, $task);

            if ($success) {
                $dispatched++;
            }
        }

        $this->log("已派发 {$dispatched} 个任务");

        return $dispatched;
    }

    /**
     * 派发单个任务
     */
    private function dispatchTask(string $agentId, array $task): bool
    {
        $targetFile = $task['file'];

        if (!str_starts_with($targetFile, BP)) {
            $targetFile = BP . ltrim($targetFile, '/\\');
        }

        $dir = dirname($targetFile);
        PlatformHelper::ensureDirectoryExists($dir);

        $this->ensureFileExists($targetFile, $task);

        $this->getTaskPool()->updateStatus($agentId, 'running');
        $this->getTaskPool()->save();

        $matchResult = [
            'target_file' => $targetFile,
            'target_line' => 1,
            'issues' => ['需要实现: ' . $task['description']],
        ];

        $taskInfo = [
            'text' => $task['description'],
            'code_id' => $agentId,
            'priority' => $task['priority'],
            'file' => $targetFile,
            'line' => 1,
        ];

        $success = $this->getDispatcher()->dispatch($agentId, $taskInfo, $matchResult);

        if ($success) {
            $this->activeInstances[$agentId] = [
                'file' => $targetFile,
                'started_at' => time(),
            ];

            $this->log("派发任务 {$agentId} -> {$targetFile}");
        } else {
            $this->getTaskPool()->updateStatus($agentId, 'todo');
            $this->getTaskPool()->save();

            $this->log("派发失败: {$agentId}");
        }

        return $success;
    }

    /**
     * 确保文件存在
     */
    private function ensureFileExists(string $filePath, array $task): void
    {
        if (file_exists($filePath)) {
            return;
        }

        $content = FileTemplateHelper::createTemplate($filePath, $task);
        file_put_contents($filePath, $content);
    }

    /**
     * 检查活跃实例状态
     */
    public function checkActiveInstances(): array
    {
        $completed = [];
        $failed = [];

        foreach ($this->activeInstances as $agentId => $instance) {
            $status = $this->checkInstanceStatus($agentId, $instance);

            switch ($status) {
                case 'completed':
                    $completed[] = $agentId;
                    unset($this->activeInstances[$agentId]);
                    break;

                case 'failed':
                    $failed[] = $agentId;
                    unset($this->activeInstances[$agentId]);
                    break;

                case 'timeout':
                    $this->log("任务超时: {$agentId}");
                    $failed[] = $agentId;
                    unset($this->activeInstances[$agentId]);
                    break;
            }
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'active' => array_keys($this->activeInstances),
        ];
    }

    /**
     * 检查实例状态
     */
    private function checkInstanceStatus(string $agentId, array $instance): string
    {
        $filePath = $instance['file'];

        if (time() - $instance['started_at'] > 600) {
            return 'timeout';
        }

        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);

            if (str_contains($content, '@Status: Completed')) {
                return 'completed';
            }

            $taskStatus = $this->getDispatcher()->checkTaskStatus($agentId);

            if ($taskStatus['completed']) {
                return 'completed';
            }
        }

        return 'running';
    }

    /**
     * 获取活跃实例数
     */
    public function getActiveCount(): int
    {
        return count($this->activeInstances);
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[CursorDriver] {$message}\n";
        }

        $logFile = BP . 'var/log/cursor-driver.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
