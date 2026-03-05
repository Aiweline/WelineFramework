<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时：创建默认管理员 admin/admin，并为 user_id=1 分配默认角色（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->seedDefaultAdmin();
        $this->seedDefaultRoleForUser1();
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

    /** 仅在管理员 ID=1 存在时分配默认角色 role_id=1 */
    private function seedDefaultRoleForUser1(): void
    {
        $backendUser = ObjectManager::getInstance(BackendUser::class);
        $backendUser->reset()->load(1);
        if (!$backendUser->getId()) {
            return;
        }
        /** @var UserRole $userRole */
        $userRole = ObjectManager::getInstance(UserRole::class);
        $exist = $userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, (int) $backendUser->getId())
            ->find()
            ->fetch();
        if ($exist && $exist->getData(UserRole::schema_fields_ROLE_ID)) {
            return;
        }
        $userRole->clear()
            ->setUserId((int) $backendUser->getId())
            ->setRoleId(1)
            ->save(true);
    }
}
