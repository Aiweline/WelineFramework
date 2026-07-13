<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

/**
 * Clears the framework cache pools owned by one Worker process.
 *
 * A false result remains a hard failure for cache-epoch publication. Missing
 * shared namespaces are normalized as an idempotent success by the shared
 * memory service, so false here represents a real adapter/protocol failure.
 */
final class WorkerCachePoolResetter
{
    private const POOLS = [
        'router',
        'fpc',
        'hook',
        'view',
        'phrase',
        'i18n',
        'config',
        'module_router',
        'theme',
        'url_rewrite',
        'website',
        'controller',
        'taglib',
        'system_config',
    ];

    /**
     * @return array<string, bool>
     */
    public static function clearFrameworkPools(): array
    {
        WlsMemoryAdapter::clearAllMemory();

        $cacheManager = ObjectManager::getInstance(CacheManager::class);
        $results = [];
        foreach (self::POOLS as $pool) {
            if (!$cacheManager->hasPool($pool)) {
                continue;
            }

            try {
                $results[$pool] = $cacheManager->pool($pool)->clear();
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'cache_pool_clear_exception:' . $pool . ':' . $throwable->getMessage(),
                    0,
                    $throwable,
                );
            }
        }

        return $results;
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
