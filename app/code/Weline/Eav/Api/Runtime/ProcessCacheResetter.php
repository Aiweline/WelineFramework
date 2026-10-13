<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Runtime;

use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        // Process bag is rebuildable content: soft memory pressure may clear it;
        // hard / explicit cache clear always clear it. No parallel static bag.
        if ($context->isSoftContent() || $context->allowsRegistryClear() || $context->isExplicitCacheClear()) {
            AttributeMetadataCatalog::clearProcessCache();

            return 1;
        }

        return 0;
    }

    public function diagCounts(): array
    {
        return [];
    }
}
