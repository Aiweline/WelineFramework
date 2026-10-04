<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\WorkerCachePoolResetter;

final class WorkerCachePoolResetterTest extends TestCase
{
    public function testWorkerCacheEpochKeepsTheSharedRouterFilePool(): void
    {
        $root = BP . 'var/tmp/wls-worker-cache-resetter-' . \bin2hex(\random_bytes(6));
        $adapter = new FileAdapter('router', ['path' => $root]);
        $pool = new CachePool('router', $adapter);
        $pool->set('published-homepage', 'still-published', 60);
        $manager = new class($pool) extends CacheManager {
            public function __construct(private readonly CachePoolInterface $routerPool) {}
            public function hasPool(string $identity): bool { return $identity === 'router'; }
            public function pool(string $identity): CachePoolInterface { return $this->routerPool; }
        };
        $previous = ObjectManager::_getInstance(CacheManager::class);
        ObjectManager::setInstance(CacheManager::class, $manager);

        try {
            WorkerCachePoolResetter::clearFrameworkPools();
            self::assertSame('still-published', $pool->get('published-homepage'));
        } finally {
            if ($previous !== null) {
                ObjectManager::setInstance(CacheManager::class, $previous);
            } else {
                ObjectManager::removeInstance(CacheManager::class);
            }
            $adapter->clear();
            @\rmdir($root . '/router');
            @\rmdir($root);
        }
    }
}
