<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * 运行时拓扑一致性判定。
 *
 * `auto` 的首选仍是 Direct；只有 Direct 能力经探测确实不可用时，解析器才会
 * 降级为 Dispatcher。该降级是预检意图（`auto → Direct`）与最终选择
 * （`auto → Dispatcher`）之间**唯一**允许的差异，且必须带下降级原因码，
 * 避免任何未声明的静默改写。
 */
final class RuntimeTopologyConsistency
{
    /**
     * `auto` 因直连能力缺失而降级 Dispatcher 的唯一原因码。
     */
    public const AUTO_DISPATCHER_FALLBACK_REASON_CODE = 'auto_direct_unavailable_dispatcher_fallback';

    /**
     * 判断最终选择是否可被依赖预检意图接受。
     */
    public static function accepts(
        RuntimeSelection $selection,
        RequestedTopology $expectedRequested,
        EffectiveTopology $expectedEffective,
    ): bool {
        if ($selection->requestedTopology === $expectedRequested
            && $selection->effectiveTopology === $expectedEffective
        ) {
            return true;
        }

        return self::isAutoDispatcherFallback($expectedRequested, $expectedEffective, $selection);
    }

    /**
     * 仅 `auto` 请求（预检为 Direct）降级为 Dispatcher 且带原因码时成立。
     */
    public static function isAutoDispatcherFallback(
        RequestedTopology $expectedRequested,
        EffectiveTopology $expectedEffective,
        RuntimeSelection $selection,
    ): bool {
        return $expectedRequested === RequestedTopology::Auto
            && $expectedEffective === EffectiveTopology::Direct
            && $selection->requestedTopology === RequestedTopology::Auto
            && $selection->effectiveTopology === EffectiveTopology::Dispatcher
            && \in_array(self::AUTO_DISPATCHER_FALLBACK_REASON_CODE, $selection->reasonCodes, true);
    }
}
