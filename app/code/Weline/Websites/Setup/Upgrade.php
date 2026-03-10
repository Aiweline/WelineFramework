<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Websites\Model\DomainPool;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级：根据 website_domain 表回填 domain_pool.site_created 字段（1.6.1 新增）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }
}
