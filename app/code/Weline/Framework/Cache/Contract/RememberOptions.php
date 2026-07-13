<?php

declare(strict_types=1);

/**
 * Remember 行为选项（DTO）
 *
 * 用于驱动 RemembererInterface::remember() 的运行参数：
 * - 防穿透：null 哨兵 + 短 TTL
 * - 防雪崩：TTL 抖动
 * - 防击穿：single-flight 协调
 * - 热点识别：HotKeyTracker 钩子
 *
 * 所有字段都有合理默认值，调用方按需覆盖即可。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

final class RememberOptions
{
    /** 与缓存中真实 null 区分的哨兵字符串（仅 Remember 内部使用） */
    public const NULL_SENTINEL = '__weline_cache_null_sentinel__';

    public function __construct(
        /** 命中负值（回调返回 null）时的短 TTL（秒），用于防穿透；<=0 表示沿用池默认 TTL */
        public int $nullTtl = 60,

        /** 是否启用 TTL 抖动；null 表示沿用池默认设置 */
        public ?bool $jitter = null,

        /** 抖动比例覆盖；null 表示沿用池默认 */
        public ?float $jitterRatio = null,

        /** 是否启用 single-flight（仅一个并发执行回调，其它等待） */
        public bool $singleFlight = true,

        /** single-flight 锁超时（毫秒）。超时后回退为「直接执行回调」以保证可用性 */
        public int $singleFlightTimeoutMs = 1500,

        /** 是否将 key 计入热点统计 */
        public bool $hotKeyTrack = true,

        /** 当 key 被识别为热点后的处理回调（接收 key/value/ttl，可决定是否写入 Redis 副本等） */
        public mixed $hotKeyHandler = null,

        /** 是否允许在锁未获取且 stale 数据可用时返回 stale（保可用降级） */
        public bool $serveStaleOnLock = false,

        /** single-flight 超时且二次读取仍 miss 时，是否允许当前请求自行回源 */
        public bool $computeOnSingleFlightTimeout = true
    ) {
    }
}
