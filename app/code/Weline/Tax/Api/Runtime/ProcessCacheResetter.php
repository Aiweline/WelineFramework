<?php

declare(strict_types=1);

namespace Weline\Tax\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Tax\Service\TaxEngine;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        TaxEngine::clearProcessCache();

        return 1;
    }
    public function diagCounts(): array
    {
        return [];
    }

}
