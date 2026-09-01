<?php

declare(strict_types=1);

namespace Weline\Marketing\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Marketing\Model\Rule\LocalDescription;

/** Ensure rule local-description table exists for list joins. */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var LocalDescription $local */
        $local = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($local);
        $local->setup($modelSetup, $context);
    }
}
