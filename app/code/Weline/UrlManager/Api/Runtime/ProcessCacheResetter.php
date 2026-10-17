<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Runtime;

use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\UrlManager\Observer\RouterRewrite;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        SeoUrlGenerateRewrite::clearProcessCache();
        RouterRewrite::clearProcessCache();
        Url::clearProcessDecodeCache();

        return 3;
    }
    public function diagCounts(): array
    {
        return [];
    }

}
