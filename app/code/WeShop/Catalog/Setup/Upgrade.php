<?php

declare(strict_types=1);

namespace WeShop\Catalog\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * 模块升级脚本
 */
class Upgrade implements \Weline\Framework\Setup\UpgradeInterface
{
    /**
     * 升级模块
     * 
     * @param Setup $setup
     * @param Context $context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var UpgradeData $upgradeData */
        $upgradeData = ObjectManager::getInstance(UpgradeData::class);
        $upgradeData->install();
    }
}
