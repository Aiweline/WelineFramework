<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * 信号弹操作接口
 * 
 * 职责：[SUPERVISOR_TASK] 信号弹的注入和清理
 * 信号弹是触发 .cursorrules 协议的关键标记
 */
interface SignalFlareInterface
{
    /**
     * 注入信号弹到文件
     *
     * @param string $filePath 目标文件路径
     * @param string $agentId 智能体 ID
     * @param array $task 任务信息
     * @return bool 是否注入成功
     */
    public function inject(string $filePath, string $agentId, array $task): bool;

    /**
     * 清理文件中的信号弹
     *
     * @param string $filePath 目标文件路径
     * @param string $agentId 智能体 ID
     * @return bool 是否清理成功
     */
    public function cleanup(string $filePath, string $agentId): bool;

    /**
     * 检查文件是否包含信号弹
     *
     * @param string $filePath 文件路径
     * @return bool
     */
    public function hasSignalFlare(string $filePath): bool;

    /**
     * 获取文件中的信号弹信息
     *
     * @param string $filePath 文件路径
     * @return array|null 信号弹信息或 null
     */
    public function getSignalFlareInfo(string $filePath): ?array;
}
