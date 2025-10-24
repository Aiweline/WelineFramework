<?php

namespace Weline\ElfinderFileManager\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        require __DIR__ . '/../config.php';
    }
}
