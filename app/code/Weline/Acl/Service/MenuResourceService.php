<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Resource\MenuResourceServiceInterface;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;

final class MenuResourceService implements MenuResourceServiceInterface
{
    public function __construct(
        private readonly ResourceTreeService $resourceTreeService,
    ) {
    }

    public function getBackendMenuTreeByRoleId(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }

        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class, [], false);
        $role->load($roleId);
        return $role->getId() > 0 ? $this->resourceTreeService->getBackendMenuTree($role) : [];
    }

    public function getEnabledBackendMenuRoutes(): array
    {
        return $this->resourceTreeService->getEnabledBackendMenuRoutes();
    }

    public function getAllMenuResources(): array
    {
        return $this->resourceTreeService->getAllMenuResources();
    }

    public function buildManagementTree(array $menus, string $parentSource = ''): array
    {
        return $this->resourceTreeService->buildMenuManagementTree($menus, $parentSource);
    }

    public function findMenuResource(int|string $id): ?array
    {
        $menu = $this->resourceTreeService->loadMenuResource($id);
        return $menu?->getSourceId() ? $menu->getData() : null;
    }

    public function hasMenuChildren(string $sourceId): bool
    {
        return $this->resourceTreeService->hasMenuChildren($sourceId);
    }

    public function saveMenuResource(array $data): array
    {
        $mapped = $this->mapMenuData($data);
        $sourceId = (string)($mapped[Acl::schema_fields_SOURCE_ID] ?? '');
        /** @var Acl $menu */
        $menu = ObjectManager::getInstance(Acl::class, [], false);
        if ($sourceId !== '') {
            $menu->load($sourceId, Acl::schema_fields_SOURCE_ID);
        }
        $menu->addData($mapped);
        $menu->setType(Acl::type_MENUS)->save();
        return $menu->getData();
    }

    public function deleteMenuResource(int|string $id): bool
    {
        $menu = $this->resourceTreeService->loadMenuResource($id);
        if (!$menu?->getSourceId()) {
            return false;
        }
        $menu->delete();
        return true;
    }

    public function updateMenuOrder(int|string $id, int $order): bool
    {
        $menu = $this->resourceTreeService->loadMenuResource($id);
        if (!$menu?->getSourceId()) {
            return false;
        }
        $menu->setData(Acl::schema_fields_ORDER, $order)->save();
        return true;
    }

    public function findEnabledBackendMenuSource(string $route, string $method = ''): ?string
    {
        $route = trim($route, '/');
        if ($route === '') {
            return null;
        }

        $menu = $this->findMenuByRoute($route, strtoupper($method));
        if ($menu === null && $method !== '') {
            $menu = $this->findMenuByRoute($route, '');
        }
        return $menu[Acl::schema_fields_SOURCE_ID] ?? null;
    }

    public function getAccessibleMenuResources(int $roleId, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $sourceId): string => trim((string)$sourceId), $sourceIds),
            static fn(string $sourceId): bool => $sourceId !== ''
        )));
        if ($roleId <= 0 || $sourceIds === []) {
            return [];
        }

        $requested = array_fill_keys($sourceIds, true);
        $allowed = $requested;
        if ($roleId !== 1) {
            /** @var RoleAccess $roleAccess */
            $roleAccess = ObjectManager::getInstance(RoleAccess::class, [], false);
            $rows = $roleAccess->fields(RoleAccess::schema_fields_SOURCE_ID)
                ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
                ->select()
                ->fetchArray();
            $allowed = [];
            foreach ($rows as $row) {
                $sourceId = (string)($row[RoleAccess::schema_fields_SOURCE_ID] ?? '');
                if ($sourceId !== '' && isset($requested[$sourceId])) {
                    $allowed[$sourceId] = true;
                }
            }
        }
        if ($allowed === []) {
            return [];
        }

        /** @var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class, [], false);
        $rows = $acl->where(Acl::schema_fields_SOURCE_ID, array_keys($allowed), 'in')
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();
        $resources = [];
        foreach ($rows as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sourceId !== '' && isset($allowed[$sourceId])) {
                $resources[$sourceId] = $row;
            }
        }
        return $resources;
    }

    private function findMenuByRoute(string $route, string $method): ?array
    {
        /** @var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class, [], false);
        $acl->where(Acl::schema_fields_ROUTE, $route)
            ->where(Acl::schema_fields_IS_BACKEND, 1)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS);
        if ($method !== '') {
            $acl->where(Acl::schema_fields_METHOD, $method);
        }
        $row = $acl->find()->fetch();
        return $row->getId() ? $row->getData() : null;
    }

    private function mapMenuData(array $data): array
    {
        $mapped = [];
        $sourceId = $data['source_id'] ?? $data['source'] ?? null;
        if ($sourceId !== null) {
            $mapped[Acl::schema_fields_SOURCE_ID] = (string)$sourceId;
        }
        $sourceName = $data['title'] ?? $data['source_name'] ?? null;
        if ($sourceName !== null) {
            $mapped[Acl::schema_fields_SOURCE_NAME] = (string)$sourceName;
        }
        $route = $data['action'] ?? $data['route'] ?? null;
        if ($route !== null) {
            $mapped[Acl::schema_fields_ROUTE] = trim((string)$route, '/');
        }
        foreach (['parent_source', 'icon', 'module'] as $field) {
            if (array_key_exists($field, $data)) {
                $mapped[$field] = (string)$data[$field];
            }
        }
        foreach (['order', 'is_enable', 'is_backend', 'acl_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $mapped[$field] = (int)$data[$field];
            }
        }
        $mapped[Acl::schema_fields_TYPE] = Acl::type_MENUS;
        $mapped[Acl::schema_fields_ACL_ORIGIN] = Acl::acl_origin_user;
        return $mapped;
    }
}
