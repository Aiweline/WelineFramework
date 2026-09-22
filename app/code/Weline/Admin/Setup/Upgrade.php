<?php

declare(strict_types=1);

namespace Weline\Admin\Setup;

use Weline\Admin\Service\BackendMenuSearchIndexRebuilder;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        unset($setup, $context);
        BackendMenuSearchIndexRebuilder::rebuild(0);
    }
}
