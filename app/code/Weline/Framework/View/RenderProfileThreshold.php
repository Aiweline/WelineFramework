<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Single source of truth for template/render profiling thresholds.
 *
 * The "is this render worth recording" decision used to be a literal `20.0`
 * copied into three places (`Template::recordProfile()` twice, `PcController`
 * once), with subtly different extra conditions. Two consequences:
 *
 *  1. Changing the threshold required editing every copy, and the copies could
 *     silently drift apart.
 *  2. `Template` applied the cutoff only while the trace panel was closed, so the
 *     set of recorded components differed between trace-on and trace-off runs —
 *     the two measurement modes were not comparable.
 *
 * Centralising the number here makes the value tunable in one place and keeps the
 * three call sites on the same rule.
 */
final class RenderProfileThreshold
{
    /**
     * Minimum render duration, in milliseconds, before a component is recorded.
     *
     * Kept at the historical value so existing baselines stay comparable. Raise it
     * to cut log volume, lower it when hunting for many small costs.
     */
    public const SLOW_RENDER_MS = 20.0;

    /**
     * Whether a render of this duration should be recorded.
     *
     * Both the total and the include span are checked: a component can be fast
     * overall while its own template include dominates its parent's budget, and
     * that case matters when attributing cost.
     */
    public static function isSlowEnough(float $totalMs, float $includeMs = 0.0): bool
    {
        return $totalMs >= self::SLOW_RENDER_MS || $includeMs >= self::SLOW_RENDER_MS;
    }
}
