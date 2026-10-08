<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Seo\Model\WebsiteProtocolConfig;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        WebsiteProtocolConfig::clearProcessCache();

        return 1;
    }
    public function diagCounts(): array
    {
        return [];
    }

}
