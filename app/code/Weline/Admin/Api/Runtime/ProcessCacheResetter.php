<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Runtime;

use Weline\Admin\Controller\BaseController;
use Weline\Admin\Service\MenuRenderService;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        BaseController::clearRuntimeFullPageCache();
        MenuRenderService::clearProcessCache();

        // soft / hard / explicit cache_clear all reclaim menu HTML process caches.
        return 2;
    }
}
