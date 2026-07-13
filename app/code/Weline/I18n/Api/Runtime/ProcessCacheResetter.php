<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\I18n\Parser;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if (!$context->isExplicitCacheClear()) {
            return 0;
        }

        Parser::clearWorkerCaches();
        return 1;
    }
}
