<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Engine\MysqlSearchEngine;

/**
 * Lazy bridge so providers can resolve hub services without constructor cycles in tests.
 */
final class ObjectManagerBridge
{
    public static function engineResolver(): SearchEngineResolver
    {
        return ObjectManager::getInstance(SearchEngineResolver::class);
    }

    public static function hub(): SearchHubService
    {
        return ObjectManager::getInstance(SearchHubService::class);
    }
}
