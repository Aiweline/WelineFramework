<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * 任务池接口
 * 
 * 职责：全局任务看板，记录每个智能体的任务状态
 */
interface TaskPoolInterface
{
    /**
     * 加载任务池
     *
     * @return self
     */
    public function load(): self;

    /**
     * 保存任务池
     *
     * @return bool
     */
    public function save(): bool;

    /**
     * 添加任务
     *
     * @param string $agentId 智能体 ID
     * @param string $targetFile 目标文件
     * @param string $description 任务描述
     * @param string|null $dependency 依赖任务 ID
     * @param string $priority 优先级
     * @return self
     */
    public function addTask(
        string $agentId,
        string $targetFile,
        string $description,
        ?string $dependency = null,
        string $priority = 'normal'
    ): self;

    /**
     * 更新任务状态
     *
     * @param string $agentId 智能体 ID
     * @param string $status 状态
     * @return self
     */
    public function updateStatus(string $agentId, string $status): self;

    /**
     * 获取就绪任务（可执行）
     *
     * @return array
     */
    public function getReadyTasks(): array;

    /**
     * 获取运行中的任务
     *
     * @return array
     */
    public function getRunningTasks(): array;

    /**
     * 按状态获取任务
     *
     * @param string $status
     * @return array
     */
    public function getTasksByStatus(string $status): array;

    /**
     * 获取任务统计
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * 获取配置
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed;

    /**
     * 获取 Agents 目录
     *
     * @return string
     */
    public function getAgentsDir(): string;

    /**
     * 获取 Master 状态
     *
     * @return array
     */
    public function getMasterStatus(): array;

    /**
     * 获取任务池原始数据
     *
     * @return array
     */
    public function getPool(): array;
}
