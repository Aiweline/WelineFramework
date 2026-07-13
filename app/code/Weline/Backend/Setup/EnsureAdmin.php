<?php

declare(strict_types=1);

namespace Weline\Backend\Setup;

use Weline\Acl\Api\Role\RoleAdministrationInterface;
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
        private readonly ?object $legacyRoleModel = null,
        private readonly ?UserRole $userRoleModel = null,
        private readonly ?RoleAdministrationInterface $roleAdministration = null,
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

        $roleId = $this->ensureSuperAdminRole();

        $userRole = $this->createUserRoleModel();
        $existingRelation = $userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, (int) $user->getId())
            ->where(UserRole::schema_fields_ROLE_ID, $roleId)
            ->find()
            ->fetch();

        if ($existingRelation->getUserId() && $existingRelation->getRoleId()) {
            return;
        }

        $userRole->clearData()
            ->setUserId((int) $user->getId())
            ->setRoleId($roleId)
            ->save(true);
    }

    private function createBackendUserModel(): BackendUser
    {
        return clone ($this->backendUserModel ?? ObjectManager::getInstance(BackendUser::class));
    }

    private function ensureSuperAdminRole(): int
    {
        if ($this->legacyRoleModel !== null) {
            $role = clone $this->legacyRoleModel;
            $role->load(1);
            if (!$role->getId()) {
                $role->clear()
                    ->setRoleName('Super Admin')
                    ->setRoleDescription('System built-in super admin role')
                    ->save();
            }
            return (int)($role->getId() ?: 1);
        }

        $administration = $this->roleAdministration
            ?? ObjectManager::getInstance(RoleAdministrationInterface::class);
        return $administration->ensure(1, 'Super Admin', 'System built-in super admin role');
    }

    private function createUserRoleModel(): UserRole
    {
        return clone ($this->userRoleModel ?? ObjectManager::getInstance(UserRole::class));
    }
}
