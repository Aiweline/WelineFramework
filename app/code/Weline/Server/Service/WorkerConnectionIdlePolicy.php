<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Decide when an accepted client connection should be force-closed for idle /
 * write-stall reasons. Extracted so HTTP/1.1 and HTTP/2 share one policy:
 * never wait forever on a half-open or stalled peer.
 *
 * Important: keep-alive "activity" (PINGs, empty SSL reads, control frames) must
 * NOT reset the write-stall clock. Callers pass $progressIdleSec based on real
 * response-byte progress (socket write or H2 pending-response drain).
 */
final class WorkerConnectionIdlePolicy
{
    public const ACTION_KEEP = 'keep';
    public const ACTION_CLOSE_IDLE = 'close_idle';
    public const ACTION_CLOSE_STALL = 'close_stall';

    /**
     * Ecommerce storefront defaults: prefer fast fail over long reuse.
     * Keep-Alive ~45s reuses pages; write-stall ~20s unblocks pending assets.
     */
    public const DEFAULT_KEEP_ALIVE_SEC = 45;
    public const DEFAULT_WRITE_STALL_SEC = 20;
    public const DEFAULT_TIMEOUT_CHECK_INTERVAL_SEC = 3;

    public static function decide(
        float $idleSec,
        float $keepAliveTimeoutSec,
        float $writeStallTimeoutSec,
        bool $hasBufferedOrPendingResponse,
        bool $hasActiveRequestWork = false,
        ?float $progressIdleSec = null,
    ): string {
        $keepAliveTimeoutSec = \max(1.0, $keepAliveTimeoutSec);
        $writeStallTimeoutSec = \max(1.0, $writeStallTimeoutSec);
        $progressIdleSec = $progressIdleSec ?? $idleSec;

        if ($hasBufferedOrPendingResponse) {
            return $progressIdleSec >= $writeStallTimeoutSec
                ? self::ACTION_CLOSE_STALL
                : self::ACTION_KEEP;
        }

        if ($hasActiveRequestWork) {
            // In-flight request with no response bytes yet: abort on progress
            // stall (not PING/empty-read activity), so browsers never sit forever.
            return $progressIdleSec >= \max($keepAliveTimeoutSec, $writeStallTimeoutSec)
                ? self::ACTION_CLOSE_STALL
                : self::ACTION_KEEP;
        }

        return $idleSec >= $keepAliveTimeoutSec
            ? self::ACTION_CLOSE_IDLE
            : self::ACTION_KEEP;
    }
}
