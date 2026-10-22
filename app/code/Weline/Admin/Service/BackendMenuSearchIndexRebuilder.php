<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderIndexService;

/**
 * Rebuild backend_menu provider index after menu/ACL changes.
 */
final class BackendMenuSearchIndexRebuilder
{
    public static function rebuild(int $websiteId = 0): int
    {
        if (!class_exists(SearchProviderIndexService::class)) {
            return 0;
        }

        try {
            /** @var SearchProviderIndexService $indexService */
            $indexService = ObjectManager::getInstance(SearchProviderIndexService::class);

            $indexService->enqueueRebuild('backend_menu', $websiteId);

            return 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
