<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Multipass\Model\MultipassSite;

/**
 * 模块安装脚本
 */
class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        try {
            /** @var MultipassSite $multipassSite */
            $multipassSite = ObjectManager::getInstance(MultipassSite::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($multipassSite);
            $multipassSite->install($modelSetup, $context);

            $context->getPrinter()->success(__('Multipass 模块安装完成'));

        } catch (\Exception $e) {
            $context->getPrinter()->error(__('安装失败: %{1}', [$e->getMessage()]));
            throw $e;
        }
    }
}

