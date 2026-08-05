<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 对象 Scope 授权审计边界。
 *
 * 实现只能保存动作、阶段、判定和 Scope 摘要，不得记录请求体、配置值或对象内容。
 */
interface ObjectAuthorizationAuditInterface
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
    ): void;
}
