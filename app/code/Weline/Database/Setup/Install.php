<?php

declare(strict_types=1);

/**
 * Weline_Database 模块安装脚本
 *
 * 表结构由各 Model 的 install() 方法创建，此处无需额外操作。
 */

namespace Weline\Database\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

class Install
{
    public function setup(Setup $setup, Context $context): void
    {
    }
}
