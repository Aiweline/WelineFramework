<?php

declare(strict_types=1);

namespace Weline\Cron;

/**
 * 计划任务 CPU 预算声明（可选实现）。
 *
 * 调度子进程在执行 {@see \Weline\Cron\CronTaskInterface::execute()} 期间按本接口声明的额度
 * 限制自身 CPU 占用：额度上限 = cpu_count × cpu_percent / 100 个 CPU 核当量，
 * 超出的部分通过睡眠出让，而不是继续抢占。这是「最多占多少」的上限，不是预留。
 *
 * 约定：
 * - 不实现本接口 = 使用默认额度（1 个 CPU、最多 30%），完全向后兼容。
 * - cpu_percent 是「单个 CPU 的百分比」；cpu_count=2 且 cpu_percent=30 等于单核的 60%。
 * - 返回 0 / 负数 / 非法值一律按默认值处理；收集端会把结果夹紧到 1..64 与 1..100 落库。
 * - 只有秒级以上的任务才可能被节流：不足 1 秒的任务在首个节拍到来前已结束。
 * - 任务内部再 fork 的子进程不计入本额度（计量只取本进程的 getrusage(0)）。
 * - 实现者不得在 execute() 内使用 pcntl_alarm() / pcntl_signal(SIGALRM) / pcntl_setitimer()：
 *   治理器用单次 SIGALRM 节拍计量并出让 CPU，父进程/宿主已就绪的闹钟会被探测并避让，
 *   但「执行体自己新装的闹钟」与治理器节拍互斥，自行安装会覆盖节拍导致限速失效。
 */
interface CronTaskCpuBudgetInterface
{
    /** 默认 CPU 个数。 */
    public const DEFAULT_CPU_COUNT = 1;

    /** 默认 CPU 占用上限（单个 CPU 的百分比）。 */
    public const DEFAULT_CPU_PERCENT = 30;

    /**
     * 允许占用的 CPU 个数。
     */
    public function cpu_count(): int;

    /**
     * 单个 CPU 允许占用的最大百分比。
     */
    public function cpu_percent(): int;
}
