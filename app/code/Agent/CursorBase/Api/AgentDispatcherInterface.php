<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * 智能体调度器接口
 * 
 * 职责：派发任务给 Cursor 智能体，管理任务状态
 */
interface AgentDispatcherInterface
{
    /**
     * 派发任务给指定智能体
     *
     * @param string $agentId 智能体 ID
     * @param array $task 任务信息
     * @param array $matchResult 代码匹配结果
     * @return bool 是否派发成功
     */
    public function dispatch(string $agentId, array $task, array $matchResult): bool;

    /**
     * 检查任务执行状态
     *
     * @param string $agentId 智能体 ID
     * @return array 状态信息
     */
    public function checkTaskStatus(string $agentId): array;

    /**
     * 检查智能体是否忙碌
     *
     * @param string $agentId 智能体 ID
     * @return bool
     */
    public function isAgentBusy(string $agentId): bool;

    /**
     * 解锁智能体
     *
     * @param string $agentId 智能体 ID
     */
    public function unlockAgent(string $agentId): void;

    /**
     * 获取活跃的智能体列表
     *
     * @return array
     */
    public function getActiveAgents(): array;

    /**
     * 写入执行状态
     *
     * @param string $agentId 智能体 ID
     * @param string $status 状态
     * @param string $message 消息
     */
    public function writeStatus(string $agentId, string $status, string $message = ''): void;

    /**
     * 设置是否自动触发 Cursor 执行
     *
     * @param bool $autoTrigger
     * @return self
     */
    public function setAutoTrigger(bool $autoTrigger): self;
}
