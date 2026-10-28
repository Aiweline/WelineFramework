<?php

declare(strict_types=1);

namespace Weline\Cron;

/**
 * 计划任务前置检查（可选实现）。
 *
 * 调度父进程在领取（claim）与派发（spawn）之前调用本方法；返回 false 时本轮不产生
 * 子进程、不写调度记录，从而让「没有待处理工作」的任务只花父进程已付过一次的启动成本。
 *
 * 约定：
 * - 不实现本接口 = 视为有活（完全向后兼容）。
 * - 只允许做文件存在性判断或单条轻量查询；禁止在本方法内启动服务、拉远端、做全表扫描。
 * - 抛异常按「有活」处理（fail-open），不得因前置检查失败而漏跑任务。
 */
interface CronTaskPreflightInterface
{
    /**
     * 本轮是否有待处理的工作。
     */
    public function hasPendingWork(): bool;
}
