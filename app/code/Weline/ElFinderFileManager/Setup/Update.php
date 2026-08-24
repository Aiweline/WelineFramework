<?php

namespace Weline\ElFinderFileManager\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;

class Update implements UpgradeInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        require __DIR__ . '/../config.php';
    }
}
