<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        // 表结构由 WelineTheme、ThemeLayout、ThemeLayoutVersion 的 #[Col] 声明
        // 经 SchemaDiffStage 自动 diff 并执行 DDL，无需手动建表。
    }
}
