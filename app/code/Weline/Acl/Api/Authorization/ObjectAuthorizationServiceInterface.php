<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 对象 Scope 授权契约（与路由 RBAC / API ScopeCatalog 分离）。
 *
 * 先校验动作是否在矩阵内，再做角色授权 Scope 与对象 Scope 交集。
 * All Sites 独立授权且永远只读；禁止 Store=* 通配写授权。
 */
interface ObjectAuthorizationServiceInterface
{
    /**
     * @param list<string>|null $actions null=沿用已存；空数组拒绝
     */
    public function authorize(int $roleId, string $action, ScopeIdentity $objectScope): ObjectAuthorizationResult;

    /**
     * 提交重鉴权：grant_version 必须仍匹配且动作仍被允许。
     */
    public function authorizeForSubmit(
        int $roleId,
        string $action,
        ScopeIdentity $objectScope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult;

    public function isObjectActionAllowed(int $roleId, string $action, ScopeIdentity $objectScope): bool;
}
