<?php
declare(strict_types=1);

/**
 * WLS Worker 扩缩容决策器
 *
 * 根据负载指标和配置决定是否需要扩缩容，以及目标 Worker 数量。
 *
 * 配置项（从 etc/env.php 读取）：
 * - wls/scaling/enabled - 是否启用自动扩缩容（默认 false）
 * - wls/scaling/min_workers - 最小 Worker 数（默认 1）
 * - wls/scaling/max_workers - 最大 Worker 数（默认 CPU 核心数 * 2）
 * - wls/scaling/scale_up_threshold_cpu - 扩容 CPU 阈值（默认 80）
 * - wls/scaling/scale_down_threshold_cpu - 缩容 CPU 阈值（默认 30）
 * - wls/scaling/scale_up_threshold_queue - 扩容队列阈值（默认 10）
 * - wls/scaling/cooldown_seconds - 冷却期（默认 60 秒）
 *
 * @author Aiweline
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

class ScalingDecider
{
    /**
     * 上次扩缩容时间戳
     */
    private float $lastScalingTime = 0.0;

    /**
     * 判断是否启用自动扩缩容
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Env::get('wls/scaling/enabled', false);
    }

    /**
     * 获取最小 Worker 数
     *
     * @return int
     */
    public function getMinWorkers(): int
    {
        return \max(1, (int)Env::get('wls/scaling/min_workers', 1));
    }

    /**
     * 获取最大 Worker 数
     *
     * @return int
     */
    public function getMaxWorkers(): int
    {
        $default = $this->getDefaultMaxWorkers();
        return \max(1, (int)Env::get('wls/scaling/max_workers', $default));
    }

    /**
     * 获取冷却期（秒）
     *
     * @return int
     */
    public function getCooldownSeconds(): int
    {
        return \max(0, (int)Env::get('wls/scaling/cooldown_seconds', 60));
    }

    /**
     * 根据负载指标决定目标 Worker 数
     *
     * @param array $metrics 聚合后的负载指标
     * @param int $currentWorkers 当前 Worker 数量
     * @return int|null 目标 Worker 数，null 表示不需要扩缩容
     */
    public function decide(array $metrics, int $currentWorkers): ?int
    {
        // 未启用自动扩缩容
        if (!$this->isEnabled()) {
            return null;
        }

        // 冷却期内不做决策
        if ($this->isInCooldown()) {
            return null;
        }

        // 没有指标数据
        if (empty($metrics) || $metrics['worker_count'] === 0) {
            return null;
        }

        $minWorkers = $this->getMinWorkers();
        $maxWorkers = $this->getMaxWorkers();

        // 当前 Worker 数已经在边界
        if ($currentWorkers < $minWorkers) {
            return $minWorkers;
        }
        if ($currentWorkers > $maxWorkers) {
            return $maxWorkers;
        }

        // 判断是否需要扩容
        if ($this->shouldScaleUp($metrics, $currentWorkers)) {
            $target = $currentWorkers + 1;
            if ($target <= $maxWorkers) {
                $this->recordScaling();
                return $target;
            }
        }

        // 判断是否需要缩容
        if ($this->shouldScaleDown($metrics, $currentWorkers)) {
            $target = $currentWorkers - 1;
            if ($target >= $minWorkers) {
                $this->recordScaling();
                return $target;
            }
        }

        return null;
    }

    /**
     * 判断是否需要扩容
     *
     * @param array $metrics 负载指标
     * @param int $currentWorkers 当前 Worker 数
     * @return bool
     */
    private function shouldScaleUp(array $metrics, int $currentWorkers): bool
    {
        $cpuThreshold = (float)Env::get('wls/scaling/scale_up_threshold_cpu', 80.0);
        $queueThreshold = (int)Env::get('wls/scaling/scale_up_threshold_queue', 10);

        // 平均 CPU 超过阈值
        if ($metrics['avg_cpu'] > $cpuThreshold) {
            return true;
        }

        // 单个 Worker CPU 超过 90%
        if ($metrics['max_cpu'] > 90.0) {
            return true;
        }

        // 总队列长度超过阈值
        if ($metrics['total_queue'] > $queueThreshold * $currentWorkers) {
            return true;
        }

        // 单个 Worker 队列过长
        if ($metrics['max_queue'] > 20) {
            return true;
        }

        return false;
    }

    /**
     * 判断是否需要缩容
     *
     * @param array $metrics 负载指标
     * @param int $currentWorkers 当前 Worker 数
     * @return bool
     */
    private function shouldScaleDown(array $metrics, int $currentWorkers): bool
    {
        // 只有一个 Worker，不缩容
        if ($currentWorkers <= 1) {
            return false;
        }

        $cpuThreshold = (float)Env::get('wls/scaling/scale_down_threshold_cpu', 30.0);

        // 平均 CPU 低于阈值 且 最大 CPU 低于 50% 且 队列很少
        return $metrics['avg_cpu'] < $cpuThreshold
            && $metrics['max_cpu'] < 50.0
            && $metrics['total_queue'] < 2 * $currentWorkers;
    }

    /**
     * 判断是否在冷却期内
     *
     * @return bool
     */
    private function isInCooldown(): bool
    {
        $cooldown = $this->getCooldownSeconds();
        if ($cooldown <= 0) {
            return false;
        }

        return (\microtime(true) - $this->lastScalingTime) < $cooldown;
    }

    /**
     * 记录扩缩容时间（用于冷却期计算）
     */
    private function recordScaling(): void
    {
        $this->lastScalingTime = \microtime(true);
    }

    /**
     * 获取默认最大 Worker 数（CPU 核心数 * 2）
     *
     * @return int
     */
    private function getDefaultMaxWorkers(): int
    {
        // 尝试获取 CPU 核心数
        if (\function_exists('shell_exec')) {
            // Linux
            $cores = (int)\shell_exec('nproc 2>/dev/null');
            if ($cores > 0) {
                return $cores * 2;
            }

            // macOS
            $cores = (int)\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($cores > 0) {
                return $cores * 2;
            }

            // Windows
            $cores = (int)\shell_exec('echo %NUMBER_OF_PROCESSORS% 2>nul');
            if ($cores > 0) {
                return $cores * 2;
            }
        }

        // 默认 4 个
        return 4;
    }

    /**
     * 重置冷却期（用于测试）
     */
    public function resetCooldown(): void
    {
        $this->lastScalingTime = 0.0;
    }
}
