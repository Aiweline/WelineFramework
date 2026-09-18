<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Runtime;

use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Service\AclService;
use Weline\Acl\Service\ResourceTreeService;
use Weline\Acl\Taglib\Acl as AclTaglib;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        AclService::resetRequestCache();
        AclService::clearProcessCache();
        RouteBefore::resetRequestCache();
        RouteBefore::clearProcessCache();
        AclTaglib::resetRequestState();
        ResourceTreeService::clearProcessCache();
        return 6;
    }
}
