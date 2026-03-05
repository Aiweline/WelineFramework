<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Observer;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Schema 变更后清理数据库相关缓存（table_ddl_after），避免表结构缓存与库不一致。
 */
class SchemaChangeClearCacheObserver implements ObserverInterface
{
    private const DATABASE_POOL = 'database';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!$this->cacheManager->hasPool(self::DATABASE_POOL)) {
            return;
        }
        $this->cacheManager->pool(self::DATABASE_POOL)->clear();
    }
}
