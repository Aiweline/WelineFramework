<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Role;

/** Read-only role catalog; ORM state never crosses this contract. */
interface RoleCatalogInterface
{
    public function find(int $roleId): ?RoleRecord;

    public function findByName(string $roleName): ?RoleRecord;

    /** @return list<RoleRecord> */
    public function list(): array;
}
