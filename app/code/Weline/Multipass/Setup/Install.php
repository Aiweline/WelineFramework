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
use Weline\Framework\Setup\InstallInterface;
use Weline\Multipass\Model\MultipassSite;

/**
 * 模块安装脚本
 */
class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->install($context);
    }

    public function install(Context $context): void
    {
        try {
            // 安装 MultipassSite 模型
            /** @var MultipassSite $multipassSite */
            $multipassSite = ObjectManager::getInstance(MultipassSite::class);
            $multipassSite->install($multipassSite->setup(), $context);

            $context->getOutput()->writeln('<info>Multipass 模块安装完成</info>');

        } catch (\Exception $e) {
            $context->getOutput()->writeln('<error>安装失败: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }
}

