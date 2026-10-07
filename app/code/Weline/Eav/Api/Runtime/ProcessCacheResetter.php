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
        AttributeMetadataCatalog::clearProcessCache();

        return 1;
    }
}
