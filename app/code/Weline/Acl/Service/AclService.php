<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\StateManager;

class AclService implements AclServiceInterface
{
    private Role $roleModel;
    private RoleAccess $roleAccessModel;
    private Acl $aclModel;

    /** 请求级缓存：路由是否受 ACL 保护，同请求内避免重复查库，WLS 下由 StateManager 重置 */
    private static array $routeProtectedCache = [];
    /** 请求级缓存：角色 ACL 条目列表，同请求内避免重复查库，WLS 下由 StateManager 重置 */
    private static array $roleAclEntriesCache = [];
    private static bool $stateManagerRegistered = false;

    public function __construct(
        Role       $roleModel,
        RoleAccess $roleAccessModel,
        Acl        $aclModel
    ) {
        $this->roleModel = $roleModel;
        $this->roleAccessModel = $roleAccessModel;
        $this->aclModel = $aclModel;
    }

    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('AclService', [self::class, 'resetRequestCache']);
            self::$stateManagerRegistered = true;
        }
    }

    /** WLS 请求结束后清空请求级缓存 */
    public static function resetRequestCache(): void
    {
        self::$routeProtectedCache = [];
        self::$roleAclEntriesCache = [];
    }

    /**
     * @inheritDoc
     */
    public function getRoleAclEntries(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        self::registerStateManager();
        if (isset(self::$roleAclEntriesCache[$roleId])) {
            return self::$roleAclEntriesCache[$roleId];
        }
        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        $entries = $this->roleAccessModel->getRoleAccessListArrayByRoleId($roleId);
        if ($t0 > 0) {
            RequestLifecycleTrace::recordSpan('acl::AclService::getRoleAclEntries_db', (microtime(true) - $t0) * 1000, 'observer');
        }
        self::$roleAclEntriesCache[$roleId] = $entries;
        return $entries;
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
        self::registerStateManager();
        if (array_key_exists($routePath, self::$routeProtectedCache)) {
            return self::$routeProtectedCache[$routePath];
        }
        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        // WLS 模式下使用新实例避免状态污染（WHERE 条件残留）
        /** @var Acl $freshAcl */
        $freshAcl = ObjectManager::getInstance(Acl::class, [], false);
        $row = $freshAcl
            ->where(Acl::schema_fields_ROUTE, $routePath)
            ->limit(1)
            ->find()
            ->fetch();
        if ($t0 > 0) {
            RequestLifecycleTrace::recordSpan('acl::AclService::isRouteProtected_db', (microtime(true) - $t0) * 1000, 'observer');
        }
        $protected = (bool)$row->getId();
        self::$routeProtectedCache[$routePath] = $protected;
        return $protected;
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
        $rows = $this->getRoleAclEntries($roleId);
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

