<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Service\MenuService;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        MenuService::clearProcessCache();
        return 1;
    }
}
