<?php

declare(strict_types=1);

namespace WeShop\Review\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        ObjectManager::getInstance(ReviewReplySchemaSetup::class)->ensure($setup);
    }
}
