<?php

declare(strict_types=1);

namespace Weline\Acl\Api;

/**
 * Public runtime name for the ACL role ORM contract.
 *
 * The exact alias keeps legacy role objects interchangeable while callers no
 * longer bind to the module's internal Model namespace.
 */
if (!\class_exists(Role::class, false)) {
    \class_alias(\Weline\Acl\Model\Role::class, Role::class);
}
