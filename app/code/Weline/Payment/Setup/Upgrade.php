<?php

declare(strict_types=1);

namespace Weline\Payment\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    /**
     * 表结构由 Model #[Col]/#[Index] + RSSE planner 负责，本钩子无 seed/data。
     */
    public function setup(Setup $setup, Context $context): void
    {
    }
}
