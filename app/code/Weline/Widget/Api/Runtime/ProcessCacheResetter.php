<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Service\WidgetRegistry;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        $cleared = 0;
        WidgetData::clearCache();
        $cleared++;

        // Aggressive keep-warm: drop in-process registry snapshot (~0.6MB static bag).
        if ($context->aggressive || $context->isExplicitCacheClear()) {
            WidgetRegistry::clearRuntimeCache();
            $cleared++;
        }

        return $cleared;
    }
}
