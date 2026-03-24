<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

/**
 * Ensure the built-in admin user and the super-admin role relation exist.
 */
class EnsureAdmin
{
    public function __construct(
        private readonly ?BackendUser $backendUserModel = null,
        private readonly ?Role $roleModel = null,
        private readonly ?UserRole $userRoleModel = null,
    ) {
    }

    public function ensureAdminUserHasRole1(): void
    {
        $this->ensure();
    }

    public function ensure(): void
    {
        $user = $this->createBackendUserModel()->load(1);

        if (!$user->getId()) {
            $user->clear()
                ->setUsername('admin')
                ->setEmail('admin@weline.com')
                ->setPassword('admin')
                ->save();
        }

        $role = $this->createRoleModel()->load(1);
        if (!$role->getId()) {
            $role->clear()
                ->setRoleName('Super Admin')
                ->setRoleDescription('System built-in super admin role')
                ->save();
        }

        $userRole = $this->createUserRoleModel();
        $existingRelation = $userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, (int) $user->getId())
            ->where(UserRole::schema_fields_ROLE_ID, (int) $role->getId())
            ->find()
            ->fetch();

        if ($existingRelation->getUserId() && $existingRelation->getRoleId()) {
            return;
        }

        $userRole->clearData()
            ->setUserId((int) $user->getId())
            ->setRoleId((int) $role->getId())
            ->save(true);
    }

    private function createBackendUserModel(): BackendUser
    {
        return clone ($this->backendUserModel ?? ObjectManager::getInstance(BackendUser::class));
    }

    private function createRoleModel(): Role
    {
        return clone ($this->roleModel ?? ObjectManager::getInstance(Role::class));
    }

    private function createUserRoleModel(): UserRole
    {
        return clone ($this->userRoleModel ?? ObjectManager::getInstance(UserRole::class));
    }
}
