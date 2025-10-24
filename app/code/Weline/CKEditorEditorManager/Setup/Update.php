<?php

namespace Weline\CKEditorEditorManager\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;

class Update implements UpgradeInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        dd(1111);
    }
}
