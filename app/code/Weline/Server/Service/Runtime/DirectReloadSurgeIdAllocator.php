<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/** Allocate an ID namespace disjoint from the currently serving Worker set. */
final class DirectReloadSurgeIdAllocator
{
    public const MIN_CANONICAL_ID = 100;
    public const GENERATION_GAP = 1000;

    public static function startInstanceId(int $maxExistingWorkerId): int
    {
        return \max(self::MIN_CANONICAL_ID, $maxExistingWorkerId)
            + self::GENERATION_GAP;
    }

    private function __construct()
    {
    }
}
