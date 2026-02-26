<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\AgentLockInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * 智能体锁管理器实现
 * 
 * 职责：管理智能体的锁定状态，防止并发冲突
 */
class AgentLockManager implements AgentLockInterface
{
    private string $lockFile;
    private bool $verbose = false;

    public function __construct()
    {
        $this->lockFile = BP . 'var' . DIRECTORY_SEPARATOR . 'agents.lock';
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 锁定智能体
     */
    public function lock(string $agentId, string $targetFile): bool
    {
        if ($this->isLocked($agentId)) {
            $this->log("智能体 {$agentId} 已被锁定");
            return false;
        }

        $locks = $this->getAllLocks();

        $locks[$agentId] = [
            'file' => $targetFile,
            'time' => time(),
            'datetime' => date('Y-m-d H:i:s'),
        ];

        $this->saveLocks($locks);
        $this->log("已锁定智能体: {$agentId}");

        return true;
    }

    /**
     * 解锁智能体
     */
    public function unlock(string $agentId): bool
    {
        $locks = $this->getAllLocks();

        if (!isset($locks[$agentId])) {
            return false;
        }

        unset($locks[$agentId]);
        $this->saveLocks($locks);
        $this->log("已解锁智能体: {$agentId}");

        return true;
    }

    /**
     * 检查智能体是否被锁定
     */
    public function isLocked(string $agentId): bool
    {
        $locks = $this->getAllLocks();

        if (!isset($locks[$agentId])) {
            return false;
        }

        $lockTime = $locks[$agentId]['time'] ?? 0;
        $maxLockDuration = 600;

        if (time() - $lockTime > $maxLockDuration) {
            $this->unlock($agentId);
            return false;
        }

        return true;
    }

    /**
     * 获取锁信息
     */
    public function getLockInfo(string $agentId): ?array
    {
        $locks = $this->getAllLocks();
        return $locks[$agentId] ?? null;
    }

    /**
     * 获取所有锁
     */
    public function getAllLocks(): array
    {
        if (!file_exists($this->lockFile)) {
            return [];
        }

        $content = file_get_contents($this->lockFile);
        return json_decode($content, true) ?: [];
    }

    /**
     * 清理过期锁
     */
    public function cleanupExpiredLocks(int $maxAge = 600): int
    {
        $locks = $this->getAllLocks();
        $cleaned = 0;
        $now = time();

        foreach ($locks as $agentId => $lock) {
            $lockTime = $lock['time'] ?? 0;
            if ($now - $lockTime > $maxAge) {
                unset($locks[$agentId]);
                $cleaned++;
                $this->log("已清理过期锁: {$agentId}");
            }
        }

        if ($cleaned > 0) {
            $this->saveLocks($locks);
        }

        return $cleaned;
    }

    /**
     * 保存锁
     */
    private function saveLocks(array $locks): void
    {
        PlatformHelper::ensureDirectoryExists(dirname($this->lockFile));
        file_put_contents($this->lockFile, json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[AgentLock] {$message}\n";
        }

        $logFile = BP . 'var/log/agent-lock.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
