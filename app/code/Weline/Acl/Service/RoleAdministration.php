<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Role\RoleAdministrationInterface;
use Weline\Acl\Model\Role;

final class RoleAdministration implements RoleAdministrationInterface
{
    public function __construct(
        private readonly Role $roleModel,
    ) {
    }

    public function ensure(int $roleId, string $name, string $description): int
    {
        if ($roleId <= 0) {
            throw new \InvalidArgumentException('Role id must be positive.');
        }

        $role = (clone $this->roleModel)->clear()->load($roleId);
        if (!$role->getId()) {
            $role->clear()
                ->setData(Role::schema_fields_ROLE_ID, $roleId)
                ->setRoleName($name)
                ->setRoleDescription($description)
                ->save();
        }

        return (int)($role->getId() ?: $roleId);
    }
}
