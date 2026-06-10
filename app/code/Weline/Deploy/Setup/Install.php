<?php

declare(strict_types=1);

namespace Weline\Deploy\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Deploy\Model\DeployRelease;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($model);
        $model->setup($modelSetup, $context);
    }
}
