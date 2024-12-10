<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Setup;

use Weline\Backend\Model\Config;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\View\Template;

class Install implements \Weline\Framework\Setup\InstallInterface
{
    /**
     * @DESC          # 安装函数：仅初次安装会执行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/1/18 20:28
     * 参数区：
     *
     * @param Data\Setup   $setup
     * @param Data\Context $context
     */
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        # 设置默认数据
        /**@var Config $config */
        $config = ObjectManager::getInstance(Config::class);
        $config->setConfig('admin_default_avatar', 'Weline_Admin::/img/logo.png', 'Weline_Admin');
    }
}
