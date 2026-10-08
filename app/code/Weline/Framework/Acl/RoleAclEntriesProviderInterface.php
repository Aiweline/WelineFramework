<?php

declare(strict_types=1);

namespace Weline\Framework\Acl;

/**
 * Role ACL entry query for Framework admin/async gates.
 * Acl module provides the implementation; Framework must not soft-pull AclService FQCN.
 */
interface RoleAclEntriesProviderInterface
{
    /**
     * @return list<array<string, mixed>|object>
     */
    public function getRoleAclEntries(int $roleId): array;
}
