<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

/** 占位：迁移由 Setup/Db/Migration 执行 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
    }
}
