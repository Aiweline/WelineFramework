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
}
