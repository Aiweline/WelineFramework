<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\AgentDispatcherInterface;
use Agent\CursorBase\Api\CursorCliInterface;
use Agent\CursorBase\Api\SignalFlareInterface;
use Agent\CursorBase\Api\AgentLockInterface;
use Agent\CursorBase\Api\KeyboardSimulatorInterface;
use Agent\CursorBase\Helper\PlatformHelper;
use Agent\CursorBase\Helper\FileTemplateHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * 智能体调度器实现
 * 
 * 职责：派发任务给 Cursor 智能体，管理任务状态
 */
class AgentDispatcher implements AgentDispatcherInterface
{
    private string $agentBaseDir;
    private bool $autoTrigger = true;
    private bool $verbose = false;

    private ?CursorCliInterface $cursorCli = null;
    private ?SignalFlareInterface $signalFlare = null;
    private ?AgentLockInterface $lockManager = null;
    private ?KeyboardSimulatorInterface $keyboard = null;

    public function __construct()
    {
        $this->agentBaseDir = BP . 'dev' . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'agents' . DIRECTORY_SEPARATOR;
        PlatformHelper::ensureDirectoryExists($this->agentBaseDir);
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 设置是否自动触发 Cursor 执行
     */
    public function setAutoTrigger(bool $autoTrigger): self
    {
        $this->autoTrigger = $autoTrigger;
        return $this;
    }

    /**
     * 获取 Cursor CLI 服务
     */
    private function getCursorCli(): CursorCliInterface
    {
        if ($this->cursorCli === null) {
            $this->cursorCli = ObjectManager::getInstance(CursorCliService::class);
        }
        return $this->cursorCli;
    }

    /**
     * 获取信号弹服务
     */
    private function getSignalFlare(): SignalFlareInterface
    {
        if ($this->signalFlare === null) {
            $this->signalFlare = ObjectManager::getInstance(SignalFlareService::class);
        }
        return $this->signalFlare;
    }

    /**
     * 获取锁管理器
     */
    private function getLockManager(): AgentLockInterface
    {
        if ($this->lockManager === null) {
            $this->lockManager = ObjectManager::getInstance(AgentLockManager::class);
        }
        return $this->lockManager;
    }

    /**
     * 获取按键模拟器
     */
    private function getKeyboard(): KeyboardSimulatorInterface
    {
        if ($this->keyboard === null) {
            $this->keyboard = ObjectManager::getInstance(KeyboardSimulator::class);
        }
        return $this->keyboard;
    }

    /**
     * 派发任务给指定智能体
     */
    public function dispatch(string $agentId, array $task, array $matchResult): bool
    {
        if ($this->isAgentBusy($agentId)) {
            $this->log("Agent {$agentId} 正忙，跳过派发");
            return false;
        }

        $targetFile = $matchResult['target_file'] ?? null;
        if (empty($targetFile)) {
            $this->log("任务无目标文件，无法派发");
            return false;
        }

        // 1. 锁定 Agent
        $this->getLockManager()->lock($agentId, $targetFile);

        // 2. 准备 Agent 决策包 (mission.json)
        $this->prepareMissionPackage($agentId, $task, $matchResult);

        // 3. 确保文件存在
        $this->ensureFileExists($targetFile, $task);

        // 4. 注入 [SUPERVISOR_TASK] 信号弹
        $injected = $this->getSignalFlare()->inject($targetFile, $agentId, $task);

        if (!$injected) {
            $this->getLockManager()->unlock($agentId);
            return false;
        }

        // 5. CLI 唤醒 Cursor 并定位
        $this->getCursorCli()->wake($targetFile, 1);

        // 6. 自动触发 Cursor 执行
        if ($this->autoTrigger) {
            sleep(2);
            $this->getKeyboard()->triggerCursorExecution();
        }

        $this->log("已派发任务给 {$agentId}");
        $this->log("   目标文件: {$targetFile}");
        $this->log("   Mission: {$this->getAgentDir($agentId)}/mission.json");

        return true;
    }

    /**
     * 准备 Agent 决策包 (mission.json)
     */
    private function prepareMissionPackage(string $agentId, array $task, array $matchResult): void
    {
        $agentDir = $this->getAgentDir($agentId);
        PlatformHelper::ensureDirectoryExists($agentDir);

        $targetFile = $matchResult['target_file'] ?? '';
        $issues = $matchResult['issues'] ?? [];

        $mission = [
            'task_id' => $task['code_id'] ?? $agentId . '_' . time(),
            'agent_id' => $agentId,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'priority' => $task['priority'] ?? 'normal',
            'instruction' => $task['text'] ?? $task['description'] ?? '',
            'target_file' => $targetFile,
            'target_method' => $task['target_method'] ?? null,
            'issues' => $issues,
            'context_files' => [
                $task['file'] ?? null,
            ],
            'rules' => [
                '必须严格按照任务描述实现功能',
                '使用强类型声明和 PSR-12 规范',
                '禁止硬编码颜色值，使用 CSS 变量',
                'JavaScript 必须使用 IIFE 闭包',
                '完成后删除 [SUPERVISOR_TASK] 注释块',
                '在代码下方写入状态确认注释',
            ],
            'status' => 'pending',
        ];

        file_put_contents(
            $agentDir . 'mission.json',
            json_encode($mission, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents($agentDir . 'status.log', '');

        $this->log("已生成决策包: {$agentDir}mission.json");
    }

    /**
     * 确保文件存在
     */
    private function ensureFileExists(string $filePath, array $task): void
    {
        if (file_exists($filePath)) {
            return;
        }

        $dir = dirname($filePath);
        PlatformHelper::ensureDirectoryExists($dir);

        $content = FileTemplateHelper::createTemplate($filePath, $task);
        file_put_contents($filePath, $content);
    }

    /**
     * 检查任务执行状态
     */
    public function checkTaskStatus(string $agentId): array
    {
        $agentDir = $this->getAgentDir($agentId);
        $statusFile = $agentDir . 'status.log';
        $missionFile = $agentDir . 'mission.json';

        $status = [
            'agent_id' => $agentId,
            'exists' => is_dir($agentDir),
            'has_mission' => file_exists($missionFile),
            'has_status' => file_exists($statusFile) && filesize($statusFile) > 0,
            'mission' => null,
            'status_log' => null,
            'completed' => false,
        ];

        if ($status['has_mission']) {
            $status['mission'] = json_decode(file_get_contents($missionFile), true);
        }

        if ($status['has_status']) {
            $status['status_log'] = trim(file_get_contents($statusFile));
            $status['completed'] = str_contains(strtolower($status['status_log']), 'completed');
        }

        return $status;
    }

    /**
     * 检查智能体是否忙碌
     */
    public function isAgentBusy(string $agentId): bool
    {
        return $this->getLockManager()->isLocked($agentId);
    }

    /**
     * 解锁智能体
     */
    public function unlockAgent(string $agentId): void
    {
        $this->getLockManager()->unlock($agentId);
    }

    /**
     * 获取活跃的智能体列表
     */
    public function getActiveAgents(): array
    {
        return $this->getLockManager()->getAllLocks();
    }

    /**
     * 写入执行状态
     */
    public function writeStatus(string $agentId, string $status, string $message = ''): void
    {
        $agentDir = $this->getAgentDir($agentId);
        $statusFile = $agentDir . 'status.log';

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$status}: {$message}\n";

        file_put_contents($statusFile, $logEntry, FILE_APPEND);

        $missionFile = $agentDir . 'mission.json';
        if (file_exists($missionFile)) {
            $mission = json_decode(file_get_contents($missionFile), true);
            $mission['status'] = $status;
            $mission['last_update'] = $timestamp;
            file_put_contents($missionFile, json_encode($mission, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 清理信号弹
     */
    public function cleanupSignalFlare(string $agentId, string $filePath): bool
    {
        $result = $this->getSignalFlare()->cleanup($filePath, $agentId);
        if ($result) {
            $this->unlockAgent($agentId);
        }
        return $result;
    }

    /**
     * 获取 Agent 目录
     */
    private function getAgentDir(string $agentId): string
    {
        return $this->agentBaseDir . $agentId . DIRECTORY_SEPARATOR;
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[AgentDispatcher] {$message}\n";
        }

        $logFile = BP . 'var/log/agent-dispatcher.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
