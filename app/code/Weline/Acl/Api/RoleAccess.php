<?php

declare(strict_types=1);

namespace Weline\Acl\Api;

/**
 * Public runtime name for ACL role-resource persistence.
 *
 * This compatibility alias preserves the existing transaction and tree
 * semantics while removing cross-module references to the internal Model path.
 */
if (!\class_exists(RoleAccess::class, false)) {
    \class_alias(\Weline\Acl\Model\RoleAccess::class, RoleAccess::class);
}
