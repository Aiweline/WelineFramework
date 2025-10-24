<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Setup;

use Weline\Framework\Setup\Data;

class Upgrade implements \Weline\Framework\Setup\UpgradeInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $setup->getDb();
        $setup->getPrinter()->setup('开始更新模块...');
    }
}
