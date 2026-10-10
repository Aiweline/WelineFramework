<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Server\Service\MemoryCacheService;

/** Server hard/clear: MemoryCacheService process bags. */
final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if ($context->isSoftContent() || !$context->allowsRegistryClear()) {
            return 0;
        }

        if (!\class_exists(MemoryCacheService::class, false)
            && !\class_exists(MemoryCacheService::class, true)
        ) {
            return 0;
        }

        MemoryCacheService::purgeAll();
        MemoryCacheService::resetStats();

        return 1;
    }

    public function diagCounts(): array
    {
        return [];
    }
}
