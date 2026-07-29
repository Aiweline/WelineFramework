<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class WorkerIpcReconnectPolicy
{
    public static function shouldScheduleReconnect(
        bool $hasClient,
        bool $connected,
        bool $shutdownReceived,
        bool $scheduledReconnectPending,
    ): bool {
        return $hasClient
            && !$connected
            && !$shutdownReceived
            && !$scheduledReconnectPending;
    }

    public static function scheduledReconnectExhausted(
        bool $scheduledReconnectPending,
        int $attempts,
        int $maxAttempts,
    ): bool {
        return $scheduledReconnectPending
            && $maxAttempts > 0
            && $attempts >= $maxAttempts;
    }
}
