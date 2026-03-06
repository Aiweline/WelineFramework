<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时：创建默认管理员 admin/admin，并为该管理员分配 role_id=1（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->seedDefaultAdmin();
        /** @var EnsureAdmin $ensureAdmin */
        $ensureAdmin = ObjectManager::getInstance(EnsureAdmin::class);
        $ensureAdmin->ensureAdminUserHasRole1();
    }

    private function seedDefaultAdmin(): void
    {
        /** @var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        if ($userModel->reset()->count() > 0) {
            return;
        }
        $userModel->clear()
            ->setUsername('admin')
            ->setEmail('admin@weline.com')
            ->setPassword('admin')
            ->save();
    }
}
