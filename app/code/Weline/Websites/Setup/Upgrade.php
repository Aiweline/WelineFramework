<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolLifecycleService;
use Weline\Websites\Service\DefaultWebsiteService;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级：1) 确保默认网站存在并绑定 127.0.0.1 / localhost；2) 回填 domain_pool.site_created
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var DefaultWebsiteService $defaultWebsiteService */
        $defaultWebsiteService = ObjectManager::getInstance(DefaultWebsiteService::class);
        $defaultWebsiteService->ensureDefaultWebsite();
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
        /** @var DomainPoolLifecycleService $lifecycle */
        $lifecycle = ObjectManager::getInstance(DomainPoolLifecycleService::class);
        $n = $lifecycle->backfillAllPoolStages();
        if ($n > 0 && \function_exists('w_log_info')) {
            \w_log_info(\sprintf('[Websites Upgrade] 已回填域名池生命周期阶段 %d 条', $n), [], 'websites_upgrade');
        }
    }
}
