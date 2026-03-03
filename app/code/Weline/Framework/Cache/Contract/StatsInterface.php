<?php

declare(strict_types=1);

/**
 * 统计能力接口（ISP: 可选能力）
 * 
 * 提供缓存命中/未命中统计。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface StatsInterface
{
    /**
     * 获取命中次数
     *
     * @return int
     */
    public function getHits(): int;

    /**
     * 获取未命中次数
     *
     * @return int
     */
    public function getMisses(): int;

    /**
     * 获取命中率（0.0 - 1.0）
     *
     * @return float
     */
    public function getHitRatio(): float;

    /**
     * 获取总请求次数
     *
     * @return int
     */
    public function getTotalRequests(): int;

    /**
     * 重置统计
     *
     * @return void
     */
    public function resetStats(): void;
}
