<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Taglib\Acl as AclTaglib;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry;
use Weline\Framework\Runtime\ProcessCacheResetContext;

/**
 * Flush ACL permission caches immediately after role_access changes.
 *
 * w_cache('acl')->clear() wipes this worker's adapter + shared memory, but other
 * WLS workers can keep serving WlsMemoryAdapter::$localCache until they observe
 * the shared epoch bump. Taglib/request/menu-tree caches are reset here too.
 */
final class AclCacheInvalidator
{
    public static function flushAfterRoleAccessChange(?int $roleId = null): void
    {
        AclService::resetRequestCache();
        RouteBefore::resetRequestCache();
        AclTaglib::resetRequestState();
        ResourceTreeService::invalidateBackendMenuTreeCache();

        try {
            $cache = w_cache('acl');
            if ($roleId !== null && $roleId > 0) {
                $cache->delete('acl_' . $roleId . '_source');
            }
            $cache->clear();
        } catch (\Throwable) {
        }

        try {
            ObjectManager::getInstance(NamespaceGenerationRepository::class)->clearSnapshot();
        } catch (\Throwable) {
        }

        ObjectManager::getInstance(CacheManager::class)->flushAll();

        ObjectManager::getInstance(ModuleProcessCacheResetterRegistry::class)->reset(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_CACHE_CLEAR, true)
        );

        ResourceTreeService::invalidateBackendMenuTreeCache();

        try {
            ObjectManager::getInstance(EventsManager::class)->dispatch(
                'Weline_Acl::role_access_cache_invalidated',
                new DataObject(['role_id' => $roleId])
            );
        } catch (\Throwable) {
        }
    }
}
