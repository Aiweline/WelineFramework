<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface ProcessCacheResetterInterface
{
    /**
     * @return int Number of process-local cache groups cleared.
     */
    public function resetProcessCaches(ProcessCacheResetContext $context): int;

    /**
     * Optional MemDiag counters (key => count). Empty = no diagnostics.
     *
     * @return array<string, int>
     */
    public function diagCounts(): array;
}
