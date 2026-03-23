<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Setup;

use Weline\Bt_Center\Model\BtServer;
use Weline\Bt_Center\Service\DefaultBtServerSeeder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        if (!$setup->getDb()->tableExist(BtServer::schema_table)) {
            return;
        }

        ObjectManager::getInstance(DefaultBtServerSeeder::class)->seed();
    }
}
