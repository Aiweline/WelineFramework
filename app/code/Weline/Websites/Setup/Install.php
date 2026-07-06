<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Websites\Service\DefaultWebsiteService;

class Install implements InstallInterface
{
    /**
     * 安装时若无默认网站则插入一条（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var DefaultWebsiteService $defaultWebsiteService */
        $defaultWebsiteService = ObjectManager::getInstance(DefaultWebsiteService::class);
        $defaultWebsiteService->ensureDefaultWebsite();
    }
}
