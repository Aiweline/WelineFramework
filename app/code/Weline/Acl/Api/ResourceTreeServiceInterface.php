<?php

declare(strict_types=1);

namespace Weline\Acl\Api;

interface ResourceTreeServiceInterface
{
    public function getBackendMenuTree(RoleIdentityInterface $role): array;

    public function getAclAssignmentTree(RoleIdentityInterface $role): array;

    public function getEnabledBackendMenuRoutes(): array;

    public function loadMenuResource(int|string $id): ?object;

    public function hasMenuChildren(string $sourceId): bool;

    public function getAllMenuResources(): array;

    public function buildMenuManagementTree(array $menus, string $parentSource = ''): array;
}
