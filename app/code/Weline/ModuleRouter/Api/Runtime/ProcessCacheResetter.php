<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\ModuleRouter\Observer\ProcessUrlBefore;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if (!$context->isExplicitCacheClear()) {
            return 0;
        }

        ProcessUrlBefore::clearCache();
        return 1;
    }
}
