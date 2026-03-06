<?php
declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

/**
 * 确保默认管理员（user_id=1 / admin）存在且拥有 role_id=1。
 * 被安装脚本与升级观察者复用，避免「用户没有分配角色」导致后台无法登录。
 */
class EnsureAdmin
{
    /**
     * 兼容旧代码调用。
     * 安装阶段直接调用该方法为默认管理员分配角色。
     */
    public function ensureAdminUserHasRole1(): void
    {
        $this->ensure();
    }

    /**
     * 升级完成后调用，保证默认管理员和角色的正确性。
     */
    public function ensure(): void
    {
        /** @var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user      = clone $userModel;

        // 优先使用 user_id=1，框架中默认将其视为超管
        $user = $user->load(1);

        // 若不存在，则兜底创建一个默认管理员账号
        if (!$user->getId()) {
            $user->clear()
                ->setUsername('admin')
                ->setEmail('admin@weline.com')
                ->setPassword('admin')
                ->save();
        }

        /** @var Role $roleModel */
        $roleModel = ObjectManager::getInstance(Role::class);
        $role      = clone $roleModel;

        // 确保 role_id=1 的超级管理员角色存在
        $role = $role->load(1);
        if (!$role->getId()) {
            $role->clear()
                ->setRoleName('超级管理员')
                ->setRoleDescription('系统内置超管角色（自动创建）')
                ->save();
        }

        /** @var UserRole $userRole */
        $userRole = ObjectManager::getInstance(UserRole::class);
        $userRole->setUserId((int)$user->getId())
            ->setRoleId((int)$role->getId())
            ->save(true);
    }
}

