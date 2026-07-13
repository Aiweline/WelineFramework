<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Role\RoleCatalogInterface;
use Weline\Acl\Api\Role\RoleRecord;
use Weline\Acl\Model\Role;

final class RoleCatalog implements RoleCatalogInterface
{
    public function __construct(
        private readonly Role $roleModel,
    ) {
    }

    public function find(int $roleId): ?RoleRecord
    {
        if ($roleId <= 0) {
            return null;
        }

        $role = (clone $this->roleModel)->clear()->load($roleId);
        return $role->getId() > 0 ? $this->map($role) : null;
    }

    public function findByName(string $roleName): ?RoleRecord
    {
        $roleName = trim($roleName);
        if ($roleName === '') {
            return null;
        }

        $role = (clone $this->roleModel)->clear()
            ->where(Role::schema_fields_ROLE_NAME, $roleName)
            ->find()
            ->fetch();
        return $role->getId() > 0 ? $this->map($role) : null;
    }

    public function list(): array
    {
        $rows = (clone $this->roleModel)->clear()
            ->order(Role::schema_fields_ROLE_ID, 'ASC')
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            $roleId = (int)($row[Role::schema_fields_ROLE_ID] ?? 0);
            if ($roleId <= 0) {
                continue;
            }
            $result[] = new RoleRecord(
                $roleId,
                (string)($row[Role::schema_fields_ROLE_NAME] ?? ''),
                (string)($row[Role::schema_fields_ROLE_DESCRIPTION] ?? ''),
            );
        }
        return $result;
    }

    private function map(Role $role): RoleRecord
    {
        return new RoleRecord(
            $role->getId(),
            (string)$role->getData(Role::schema_fields_ROLE_NAME),
            (string)$role->getData(Role::schema_fields_ROLE_DESCRIPTION),
        );
    }
}
