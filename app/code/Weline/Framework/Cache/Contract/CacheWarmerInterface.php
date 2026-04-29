<?php

declare(strict_types=1);

/**
 * 缓存预热器接口
 *
 * 实现该接口的服务会在 WLS 启动 / FPM 首次请求 / `cache:warm` 命令调用时被框架收集并执行。
 * 单一职责：每个 Warmer 只负责自己模块的缓存预热（路由、配置、翻译、ACL 等）。
 *
 * 设计目标：
 * - 在常驻内存（WLS）模式下，让首请求拥有"已经预热好的缓存"，从而消除冷启动延迟
 * - 在 FPM 模式下，可由 cron 调用 `php bin/w cache:warm` 在大流量到来前预热
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface CacheWarmerInterface
{
    /**
     * 预热器名称（用于日志/汇总输出，必须全局唯一）。
     */
    public function getName(): string;

    /**
     * 该预热器目标的缓存池标识（如 'router'、'config'、'phrase'）。
     */
    public function getTargetPool(): string;

    /**
     * 预热优先级；数值越小越早执行（建议 0~1000）。
     */
    public function getPriority(): int;

    /**
     * 当前环境是否需要执行该预热（如某模块未启用时返回 false）。
     */
    public function canWarm(): bool;

    /**
     * 执行预热。
     *
     * @return array{warmed: int, skipped: int, message?: string} 已预热的条目数 / 跳过的条目数 / 可选说明
     */
    public function warm(): array;
}
