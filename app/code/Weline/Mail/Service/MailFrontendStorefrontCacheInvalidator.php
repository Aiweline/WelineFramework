<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\View\Template;

/**
 * Clear storefront auth caches after mail frontend feature flag changes.
 */
final class MailFrontendStorefrontCacheInvalidator
{
    public function clearForMailFrontendConfig(string $reason): void
    {
        try {
            if (method_exists(Template::class, 'clearStaticHookCaches')) {
                Template::clearStaticHookCaches();
            }
        } catch (\Throwable) {
        }
        try {
            if (class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        } catch (\Throwable) {
        }
        try {
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (method_exists($cacheManager, 'hasPool') && $cacheManager->hasPool($pool)) {
                    $cacheManager->pool($pool)->clear();
                }
            }
        } catch (\Throwable) {
        }
        unset($reason);
    }
}
