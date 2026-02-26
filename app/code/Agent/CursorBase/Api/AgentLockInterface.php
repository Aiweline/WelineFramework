<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * 智能体锁管理接口
 * 
 * 职责：管理智能体的锁定状态，防止并发冲突
 */
interface AgentLockInterface
{
    /**
     * 锁定智能体
     *
     * @param string $agentId 智能体 ID
     * @param string $targetFile 目标文件
     * @return bool 是否锁定成功
     */
    public function lock(string $agentId, string $targetFile): bool;

    /**
     * 解锁智能体
     *
     * @param string $agentId 智能体 ID
     * @return bool 是否解锁成功
     */
    public function unlock(string $agentId): bool;

    /**
     * 检查智能体是否被锁定
     *
     * @param string $agentId 智能体 ID
     * @return bool
     */
    public function isLocked(string $agentId): bool;

    /**
     * 获取锁信息
     *
     * @param string $agentId 智能体 ID
     * @return array|null
     */
    public function getLockInfo(string $agentId): ?array;

    /**
     * 获取所有锁
     *
     * @return array
     */
    public function getAllLocks(): array;

    /**
     * 清理过期锁
     *
     * @param int $maxAge 最大锁定时长（秒）
     * @return int 清理的锁数量
     */
    public function cleanupExpiredLocks(int $maxAge = 600): int;
}
