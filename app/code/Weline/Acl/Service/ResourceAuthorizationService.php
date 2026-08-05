<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;

/**
 * Authorizes one immutable ACL source without route or parent inference.
 *
 * QueryProvider operations are not HTTP controller routes. Treating them as a
 * route would make an unknown/unprotected path white-listed by legacy route
 * semantics, so this service deliberately requires an existing backend source
 * and an exact role_access row.
 */
final class ResourceAuthorizationService implements ResourceAuthorizationServiceInterface
{
    public function isSourceAllowed(int $roleId, string $sourceId): bool
    {
        $sourceId = \trim($sourceId);
        if ($roleId <= 0
            || $sourceId === ''
            || \strlen($sourceId) > 127
            || \preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*::[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $sourceId) !== 1) {
            return false;
        }

        /** @var Acl $resource */
        $resource = ObjectManager::getInstance(Acl::class, [], false)
            ->fields([
                Acl::schema_fields_SOURCE_ID,
                Acl::schema_fields_IS_ENABLE,
                Acl::schema_fields_IS_BACKEND,
            ])
            ->where(Acl::schema_fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();
        if (!\hash_equals($sourceId, $resource->getSourceId())
            || !(bool)$resource->getData(Acl::schema_fields_IS_ENABLE)
            || !(bool)$resource->getData(Acl::schema_fields_IS_BACKEND)) {
            return false;
        }

        // Super administrators bypass role_access only after the exact source
        // has been proven to exist and be an enabled backend resource.
        if ($roleId === 1) {
            return true;
        }

        /** @var RoleAccess $access */
        $access = ObjectManager::getInstance(RoleAccess::class, [], false)
            ->fields(RoleAccess::schema_fields_SOURCE_ID)
            ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
            ->where(RoleAccess::schema_fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();

        return \hash_equals($sourceId, (string)$access->getData(RoleAccess::schema_fields_SOURCE_ID));
    }
}
