<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 按对象 Scope ACL 解析 urgent 显式收件人（VIEW）。
 *
 * 无权用户不会进入列表；空列表表示只写审计、零广播。
 */
final class ScopedNotificationRecipientResolver
{
    public function __construct(
        private readonly ObjectAuthorizationServiceInterface $authorization,
        private readonly UserRole $userRoleModel,
        private readonly BackendUser $userModel,
    ) {
    }

    /**
     * @return list<int>
     */
    public function resolveUserIds(ScopeIdentity $scope, string $action = ObjectAction::VIEW): array
    {
        if ($scope->isGlobal()) {
            return [];
        }

        $links = (clone $this->userRoleModel)->clearData()->reset()
            ->select()
            ->fetchArray();
        if ($links === []) {
            return [];
        }

        $allowedRoles = [];
        foreach ($links as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $roleId = (int)($link[UserRole::schema_fields_ROLE_ID] ?? 0);
            if ($roleId <= 0 || isset($allowedRoles[$roleId])) {
                continue;
            }
            $allowedRoles[$roleId] = $this->authorization->isObjectActionAllowed($roleId, $action, $scope);
        }

        $userIds = [];
        foreach ($links as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $roleId = (int)($link[UserRole::schema_fields_ROLE_ID] ?? 0);
            $userId = (int)($link[UserRole::schema_fields_USER_ID] ?? 0);
            if ($userId <= 0 || empty($allowedRoles[$roleId])) {
                continue;
            }
            $userIds[$userId] = true;
        }
        if ($userIds === []) {
            return [];
        }

        $users = (clone $this->userModel)->clearData()->reset()
            ->where(BackendUser::schema_fields_ID, \array_keys($userIds), 'IN')
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->where(BackendUser::schema_fields_is_deleted, 0)
            ->select()
            ->fetchArray();

        $out = [];
        foreach ($users as $user) {
            if (!\is_array($user)) {
                continue;
            }
            $id = (int)($user[BackendUser::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return \array_values(\array_unique($out));
    }
}
