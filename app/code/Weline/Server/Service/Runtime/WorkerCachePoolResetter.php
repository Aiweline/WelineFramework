<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

/**
 * Clears only cache state owned by one Worker process.
 * The cache-clear initiator handles shared/persistent pools. Clearing those
 * pools again in every Worker deletes the published FPC while live requests
 * are using it and turns a cache broadcast into a full SSR stampede.
 */
final class WorkerCachePoolResetter
{
    /**
     * @return array<string, bool>
     */
    public static function clearFrameworkPools(): array
    {
        WlsMemoryAdapter::clearAllMemory();
        return [];
    }

    /**
     * @param array<string, bool> $results
     * @return list<string>
     */
    public static function failedPools(array $results): array
    {
        $failed = [];
        foreach ($results as $pool => $cleared) {
            if ($cleared !== true) {
                $failed[] = (string)$pool;
            }
        }

        return $failed;
    }
}
