<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cms\Setup;

use Weline\Cms\Model\Style;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级：扫描并注册默认样式模板
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Style $styleModel */
        $styleModel = ObjectManager::getInstance(Style::class);
        $styleModel->scanAndRegisterStyles();
    }
}
