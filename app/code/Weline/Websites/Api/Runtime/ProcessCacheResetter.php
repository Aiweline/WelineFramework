<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

/**
 * Soft-clears GuoLaiRen PageBuilder frontend view HTML caches on WLS
 * cache_clear broadcasts. framework:compile only indexes Weline/* modules,
 * so PageBuilder cannot register this capability from its own module.php.
 */
final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        // Website ACL grant memo must not survive cache_clear / worker soft reset,
        // or an empty [] entry can hide newly saved grants until process recycle.
        \Weline\Websites\Service\WebsiteAclGrantService::clearRequestCache();

        $pageClass = '\\GuoLaiRen\\PageBuilder\\Controller\\Frontend\\Page';
        if (!\class_exists($pageClass) || !\is_callable([$pageClass, 'clearProcessCaches'])) {
            return 1;
        }

        $pageClass::clearProcessCaches($context->isExplicitCacheClear());

        return $context->isExplicitCacheClear() ? 3 : 2;
    }
}
