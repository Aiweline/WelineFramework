<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Setup;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Backend\Api\Notification\NotificationSeedServiceInterface;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
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
     * @param Setup $setup
     * @param Context $context
     * @throws Exception
     */
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        # 设置默认数据
        /** @var BackendConfigStore $config */
        $config = ObjectManager::getInstance(BackendConfigStore::class);
        $config->setConfig('admin_default_avatar', 'Weline_Admin::/img/logo.png', 'Weline_Admin');
        $this->seedDefaultNotifications();
    }

    private function seedDefaultNotifications(): void
    {
        /** @var NotificationSeedServiceInterface $notificationSeedService */
        $notificationSeedService = ObjectManager::getInstance(NotificationSeedServiceInterface::class);
        $notificationSeedService->seedDefaults('Weline_Admin', [[
            'title' => '欢迎来到 WelineFramework 后端！',
            'content' => 'WelineFramework框架是
一个极度灵活的集多应用的快速的互联网框架。
1、代码可移植性。
2、自定义高可用高灵活性对象ORM。
3、前后端集成到一个module中，做到一个需求一个module。
4、代码模块化，接口以及传统路由分前后台。包括接口，具有后台接口入口，后台url入口。
5、配置文件统一化。文件位置：app/etc/env.php
等等...',
            'type' => 'info',
            'is_icon' => true,
            'is_img' => false,
            'avatar' => 'ri-checkbox-circle-line',
        ], [
            'title' => '框架开发理念！',
            'content' => '灵活适应性强，高性能的基于PHP8的互联网快速开发框架...',
            'type' => 'info',
            'is_icon' => false,
            'is_img' => true,
            'avatar' => 'assets/images/users/avatar-3.jpg',
        ]]);
    }
}
