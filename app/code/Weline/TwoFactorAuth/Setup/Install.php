<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * 模块安装脚本：表结构由 Model #[Col]/#[Index] + RSSE planner 负责，本钩子无 seed/data。
 *
 * @package Weline\TwoFactorAuth\Setup
 */
class Install implements \Weline\Framework\Setup\InstallInterface
{
    /**
     * @inheritDoc
     */
    public function setup(Setup $setup, Context $context): void
    {
    }
}
