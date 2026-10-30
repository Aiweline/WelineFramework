<?php

declare(strict_types=1);

namespace Weline\Framework\Database;

/**
 * load 身份映射的写代数协调点。
 *
 * 为什么单独一个类：`AbstractModel::$loadIdentityMap` 的桶键是 request_id，而同一逻辑请求的
 * 父子 Fiber 共享同一 request_id（见 {@see \Weline\Framework\Runtime\RequestContext} 的
 * “shared memo (cross-Fiber)”）。读缓存必须能感知写，否则 A Fiber 写库后 B Fiber 仍命中写前
 * 快照。写可能来自两条路径：
 *   1. `AbstractModel::save()/delete()`（模型路径）
 *   2. 裸 SQL（`query()->update()/delete()/insert()`，Query 层路径）
 * 两处都要能 bump，但让 Query 反向依赖 Model 层是分层倒置，因此把计数器放在中立的 Database 层。
 *
 * 语义：进程内单调递增；不做 per-key 精确失效。写少读多，全量失效换取实现与验证成本最低。
 * 过失效只损失缓存命中（退化为真实查询，正确性不受影响）；欠失效才是数据错误。
 */
final class LoadIdentityGeneration
{
    private static int $generation = 0;

    /** 当前写代数；读缓存时与条目携带的代比较，不等即视为未命中。 */
    public static function current(): int
    {
        return self::$generation;
    }

    /** 标记发生了一次写操作，使所有读快照失效。 */
    public static function bump(): void
    {
        self::$generation++;
    }

    /** 测试用：复位代数，避免用例之间互相影响。 */
    public static function reset(): void
    {
        self::$generation = 0;
    }
}
