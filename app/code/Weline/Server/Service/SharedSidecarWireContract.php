<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Session/Memory sidecar wire generation.
 *
 * Worker `server:reload` does not rotate shared sidecars. When this generation
 * advances (new commands such as `mdel`), operators must explicitly
 * `server:shared:stop` then `server:shared:start` (or equivalent safe rotate).
 */
final class SharedSidecarWireContract
{
    /**
     * 1 = baseline shared NDJSON (get/set/mget/mset/…).
     * 2 = adds CMD_MDEL (Server 2.0.69+); reused pre-2 sidecars reject mdel.
     */
    public const WIRE_GENERATION = 2;

    /**
     * @param array<string, mixed> $runtime
     */
    public static function runtimeNeedsRotation(array $runtime): bool
    {
        $pid = (int)($runtime['pid'] ?? 0);
        $port = (int)($runtime['port'] ?? 0);
        if ($pid <= 0 && $port <= 0) {
            return false;
        }

        return (int)($runtime['wire_generation'] ?? 0) < self::WIRE_GENERATION;
    }
}
