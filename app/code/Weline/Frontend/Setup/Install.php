<?php

declare(strict_types=1);

namespace Weline\Frontend\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Model\System\FrontendNotification;

class Install implements InstallInterface
{
    /**
     * 安装时：默认前端用户、默认欢迎通知种子数据
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->installDefaultFrontendUser();
        $this->seedFrontendNotifications();
    }

    private function installDefaultFrontendUser(): void
    {
        /** @var FrontendUser $user */
        $user = ObjectManager::getInstance(FrontendUser::class);
        $items = $user->clear()->select()->fetch()->getItems();
        if ($items !== []) {
            return;
        }
        $user->clear()->setUsername('秋枫雁飞')->setPassword('admin')->save();
    }

    private function seedFrontendNotifications(): void
    {
        /** @var FrontendNotification $notification */
        $notification = ObjectManager::getInstance(FrontendNotification::class);
        $notification->seedInitialNotifications();
    }
}
