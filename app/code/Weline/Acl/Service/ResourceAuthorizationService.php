<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
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
        // has been proven to exist and be an enabled backend resource — unless
        // website ceiling observers deny bypass (non-default site).
        if ($roleId === 1) {
            $eventData = new DataObject([
                'role_id' => $roleId,
                'allow_bypass' => true,
                'source_id' => $sourceId,
            ]);
            try {
                ObjectManager::getInstance(EventsManager::class)->dispatch('Weline_Acl::super_admin_bypass_check', $eventData);
            } catch (\Throwable) {
                return true;
            }
            if ((bool)$eventData->getData('allow_bypass')) {
                return true;
            }
            /** @var AclServiceInterface $aclService */
            $aclService = ObjectManager::getInstance(AclServiceInterface::class);
            foreach ($aclService->getRoleAclEntries(1) as $row) {
                if (\hash_equals($sourceId, \trim((string)($row[Acl::schema_fields_SOURCE_ID] ?? '')))) {
                    return true;
                }
            }

            return false;
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
