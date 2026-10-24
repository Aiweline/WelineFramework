<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;

class Install implements InstallInterface
{
    /**
     * 安装模块：表结构由 Model #[Col]/#[Index] + RSSE planner 负责，本钩子无 seed。
     */
    public function setup(Setup $setup, Context $context): void
    {
    }
}

