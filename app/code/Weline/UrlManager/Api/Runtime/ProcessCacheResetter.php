<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        SeoUrlGenerateRewrite::clearProcessCache();

        return 1;
    }
}
