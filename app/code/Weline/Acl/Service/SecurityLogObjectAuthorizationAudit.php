<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ObjectAuthorizationAuditInterface;
use Weline\Acl\Model\SecurityLog;
use Weline\Framework\Runtime\ScopeIdentity;

final class SecurityLogObjectAuthorizationAudit implements ObjectAuthorizationAuditInterface
{
    public function record(
        int $userId,
        int $roleId,
        string $action,
        ScopeIdentity $scope,
        bool $allowed,
        string $reason,
        int $grantVersion,
        string $phase,
    ): void {
        SecurityLog::log(
            SecurityLog::EVENT_OBJECT_SCOPE_AUTHORIZATION,
            $allowed ? '对象 Scope 写入授权通过' : '对象 Scope 授权拒绝',
            [
                'action' => $action,
                'phase' => $phase,
                'allowed' => $allowed ? 1 : 0,
                'reason' => $reason,
                'role_id' => $roleId,
                'grant_version' => $grantVersion,
                'scope_kind' => $scope->scopeKind,
                'scope_digest' => \hash('sha256', $scope->canonicalKey()),
            ],
            $userId > 0 ? $userId : null,
        );
    }
}
