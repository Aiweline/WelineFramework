<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

/**
 * Data-only boundary for backend menu resources.
 *
 * No ORM model or query builder may cross this contract.
 */
interface MenuResourceServiceInterface
{
    public function getBackendMenuTreeByRoleId(int $roleId): array;

    public function getEnabledBackendMenuRoutes(): array;

    public function getAllMenuResources(): array;

    public function buildManagementTree(array $menus, string $parentSource = ''): array;

    public function findMenuResource(int|string $id): ?array;

    public function hasMenuChildren(string $sourceId): bool;

    public function saveMenuResource(array $data): array;

    public function deleteMenuResource(int|string $id): bool;

    public function updateMenuOrder(int|string $id, int $order): bool;

    public function findEnabledBackendMenuSource(string $route, string $method = ''): ?string;

    /**
     * @return array<string, array<string, mixed>> Resources keyed by source_id.
     */
    public function getAccessibleMenuResources(int $roleId, array $sourceIds): array;
}
