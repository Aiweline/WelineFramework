<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;

class AclService implements AclServiceInterface
{
    private Role $roleModel;
    private RoleAccess $roleAccessModel;
    private Acl $aclModel;

    public function __construct(
        Role       $roleModel,
        RoleAccess $roleAccessModel,
        Acl        $aclModel
    ) {
        $this->roleModel = $roleModel;
        $this->roleAccessModel = $roleAccessModel;
        $this->aclModel = $aclModel;
    }

    /**
     * @inheritDoc
     */
    public function getRoleAclEntries(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
        if (!$role->getId()) {
            return [];
        }
        // 复用现有 RoleAccess 查询，但只返回数组
        return $this->roleAccessModel->getRoleAccessListArray($role);
    }

    /**
     * @inheritDoc
     */
    public function isRouteAllowed(int $roleId, string $routePath, string $httpMethod): bool
    {
        $routePath = trim($routePath, '/');
        $httpMethod = strtoupper($httpMethod);
        if ($roleId <= 0 || $routePath === '') {
            return false;
        }
        // 超管角色（role_id=1）直接放行，由调用方决定是否需要额外约束
        if ($roleId === 1) {
            return true;
        }
        // 未定义 ACL 的路由不参与权限控制，视为白色 ACL
        if (!$this->isRouteProtected($routePath)) {
            return true;
        }

        $entries = $this->getRoleAclEntries($roleId);
        if (empty($entries)) {
            return false;
        }

        foreach ($entries as $row) {
            $route = trim((string)($row[Acl::schema_fields_ROUTE] ?? ''), '/');
            if ($route === '') {
                continue;
            }
            if ($route !== $routePath) {
                continue;
            }
            $method = strtoupper((string)($row[Acl::schema_fields_METHOD] ?? ''));
            if ($method === '' || $method === $httpMethod) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isRouteProtected(string $routePath): bool
    {
        $routePath = trim($routePath, '/');
        if ($routePath === '') {
            return false;
        }

        $row = $this->aclModel->clear()
            ->where(Acl::schema_fields_ROUTE, $routePath)
            ->limit(1)
            ->find()
            ->fetch();

        return (bool)$row->getId();
    }

    /**
     * @inheritDoc
     */
    public function hasAnyPermission(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }
        // 超管视为有权限
        if ($roleId === 1) {
            return true;
        }
        $entries = $this->getRoleAclEntries($roleId);
        return !empty($entries);
    }

    /**
     * @inheritDoc
     */
    public function hasMenuPermission(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }
        if ($roleId === 1) {
            // 超管：若 ACL 表中存在任意 menus 类型资源，则认为具备菜单权限
            $anyMenus = $this->aclModel->clear()
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->limit(1)
                ->select()
                ->fetchArray();
            return !empty($anyMenus);
        }
        $menus = $this->getMenuAclEntries($roleId);
        return !empty($menus);
    }

    /**
     * @inheritDoc
     */
    public function getMenuAclEntries(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
        if (!$role->getId()) {
            return [];
        }

        $rows = $this->roleAccessModel->getRoleAccessListArray($role);
        if (empty($rows)) {
            return [];
        }
        $menus = [];
        foreach ($rows as $row) {
            $type = (string)($row[Acl::schema_fields_TYPE] ?? '');
            if ($type !== Acl::type_MENUS && $type !== 'menus') {
                continue;
            }
            $menus[] = $row;
        }
        return $menus;
    }

    /**
     * 基于 ACL 记录为角色挑选一个“默认入口路由”（不要求 type=menus）。
     *
     * 优先选择：
     * - is_backend = 1 的资源
     * - method 为空或 GET
     * - route 非空
     *
     * @param int $roleId
     * @return string|null 不带前后斜杠的路由路径，如 admin/system/menus
     */
    public function getDefaultRouteFromAcl(int $roleId): ?string
    {
        if ($roleId <= 0) {
            return null;
        }
        $entries = $this->getRoleAclEntries($roleId);
        if (empty($entries)) {
            return null;
        }

        // 第一轮：优先选 GET/空 method 且 is_backend=1 的路由
        foreach ($entries as $row) {
            $route = trim((string)($row[Acl::schema_fields_ROUTE] ?? ''), '/');
            if ($route === '') {
                continue;
            }
            $method = strtoupper((string)($row[Acl::schema_fields_METHOD] ?? ''));
            $isBackend = (int)($row[Acl::schema_fields_IS_BACKEND] ?? 1);
            if ($isBackend !== 1) {
                continue;
            }
            if ($method === '' || $method === 'GET') {
                return $route;
            }
        }

        // 第二轮：退而求其次，返回任意有 route 的 backend 资源
        foreach ($entries as $row) {
            $route = trim((string)($row[Acl::schema_fields_ROUTE] ?? ''), '/');
            if ($route === '') {
                continue;
            }
            $isBackend = (int)($row[Acl::schema_fields_IS_BACKEND] ?? 1);
            if ($isBackend !== 1) {
                continue;
            }
            return $route;
        }

        return null;
    }
}

