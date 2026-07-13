<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Widget\Service\WidgetData;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        WidgetData::clearCache();
        return 1;
    }
}
