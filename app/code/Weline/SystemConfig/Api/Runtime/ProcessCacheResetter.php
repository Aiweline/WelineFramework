<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\SystemConfig\Model\SystemConfig;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        SystemConfig::clearProcessCache();

        return 1;
    }
}
