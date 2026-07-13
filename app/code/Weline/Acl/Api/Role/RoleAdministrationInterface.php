<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Role;

/** Write boundary for installing roles owned by the ACL module. */
interface RoleAdministrationInterface
{
    public function ensure(int $roleId, string $name, string $description): int;
}
