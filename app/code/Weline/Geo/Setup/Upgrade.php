<?php

declare(strict_types=1);

namespace Weline\Geo\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Geo\Service\EnsureDefaultFeedsService;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        ObjectManager::getInstance(EnsureDefaultFeedsService::class)->ensure();
    }
}
