<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface ProcessCacheResetterInterface
{
    /**
     * @return int Number of process-local cache groups cleared.
     */
    public function resetProcessCaches(ProcessCacheResetContext $context): int;
}
