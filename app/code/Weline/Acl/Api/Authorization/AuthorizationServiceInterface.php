<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** Scalar/data-only authorization boundary for dependent modules. */
interface AuthorizationServiceInterface
{
    public function findRouteResource(string $className, string $httpMethod, string $routePath): ?RouteResource;

    public function isRouteAllowed(int $roleId, string $routePath, string $httpMethod): bool;

    public function isRouteProtected(string $routePath): bool;

    public function hasAnyPermission(int $roleId): bool;

    public function hasMenuPermission(int $roleId): bool;

    public function getDefaultRouteFromAcl(int $roleId): ?string;

    /**
     * 对象 Scope 动作授权（P1B-004-ACL）。$objectScope 为 ScopeIdentity::toArray()。
     *
     * @param array<string, mixed> $objectScope
     */
    public function isObjectActionAllowed(int $roleId, string $action, array $objectScope): bool;

    /**
     * 提交重鉴权：expectedGrantVersion 必须仍匹配。
     *
     * @param array<string, mixed> $objectScope
     */
    public function isObjectActionAllowedForSubmit(
        int $roleId,
        string $action,
        array $objectScope,
        int $expectedGrantVersion,
    ): bool;
}
