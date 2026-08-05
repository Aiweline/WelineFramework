<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\AuthorizationServiceInterface;
use Weline\Acl\Api\Authorization\RouteResource;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\StateManager;

class AclService implements AclServiceInterface, AuthorizationServiceInterface
{
    private Role $roleModel;
    private RoleAccess $roleAccessModel;
    private Acl $aclModel;

    private const REQUEST_STATE_KEY = 'acl.service.request_cache.v1';
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
        RequestContext::remove(self::REQUEST_STATE_KEY);
    }

    /**
     * @return array{
     *     route_protected: array<string, bool>,
     *     route_equivalent_paths: array<string, array<int, string>>,
     *     role_acl_entries: array<int, array>
     * }
     */
    private static function requestState(): array
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }

        return [
            'route_protected' => is_array($state['route_protected'] ?? null)
                ? $state['route_protected']
                : [],
            'route_equivalent_paths' => is_array($state['route_equivalent_paths'] ?? null)
                ? $state['route_equivalent_paths']
                : [],
            'role_acl_entries' => is_array($state['role_acl_entries'] ?? null)
                ? $state['role_acl_entries']
                : [],
        ];
    }

    private static function storeRequestState(array $state): void
    {
        RequestContext::set(self::REQUEST_STATE_KEY, $state);
    }

    /**
     * @inheritDoc
     */
    public function findRouteResource(string $className, string $httpMethod, string $routePath): ?RouteResource
    {
        /** @var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class, [], false)
            ->fields([
                Acl::schema_fields_ID,
                Acl::schema_fields_ACL_ID,
                Acl::schema_fields_SOURCE_NAME,
            ])
            ->where(Acl::schema_fields_CLASS, $className)
            ->where(Acl::schema_fields_METHOD, $httpMethod)
            ->where(Acl::schema_fields_ROUTE, $routePath)
            ->find()
            ->fetch();

        if (!$acl->getId()) {
            return null;
        }

        return new RouteResource($acl->getAclId(), $acl->getSourceName());
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
        $state = self::requestState();
        if (isset($state['role_acl_entries'][$roleId])) {
            return $state['role_acl_entries'][$roleId];
        }
        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        $entries = $this->roleAccessModel->getRoleAccessListArrayByRoleId($roleId);
        if ($t0 > 0) {
            RequestLifecycleTrace::recordSpan('acl::AclService::getRoleAclEntries_db', (microtime(true) - $t0) * 1000, 'observer');
        }
        $state = self::requestState();
        $state['role_acl_entries'][$roleId] = $entries;
        self::storeRequestState($state);
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

        return $this->isRouteAllowedByEntries($entries, $routePath, $httpMethod);
    }

    public function isRouteAllowedByEntries(array $entries, string $routePath, string $httpMethod, bool $enforceAccessMode = false): bool
    {
        $routePath = self::normalizeRoutePath($routePath);
        $httpMethod = strtoupper($httpMethod);
        if ($routePath === '') {
            return false;
        }
        if (!$this->isRouteProtected($routePath)) {
            return true;
        }
        if (empty($entries)) {
            return false;
        }

        $routeCandidates = array_flip($this->getEquivalentRoutePaths($routePath));
        foreach ($entries as $row) {
            $route = self::normalizeRoutePath((string)$this->entryValue($row, Acl::schema_fields_ROUTE, ''));
            if ($route === '' || !isset($routeCandidates[$route])) {
                continue;
            }
            $method = strtoupper((string)$this->entryValue($row, Acl::schema_fields_METHOD, ''));
            if ($method !== '' && $method !== $httpMethod) {
                continue;
            }
            if ($enforceAccessMode && !$this->isAccessModeAllowedForMethod($row, $httpMethod)) {
                continue;
            }
            return true;
        }
        return false;
    }

    public function hasAnyAclEntries(array $entries): bool
    {
        return !empty($entries);
    }

    private function isAccessModeAllowedForMethod(mixed $entry, string $httpMethod): bool
    {
        $sourceMethod = (string)$this->entryValue($entry, Acl::schema_fields_METHOD, '');
        $mode = Acl::normalizeAccessMode(
            (string)$this->entryValue($entry, Acl::schema_fields_ACCESS_MODE, ''),
            $sourceMethod
        );
        if ($mode === Acl::ACCESS_MODE_READ) {
            return $httpMethod === 'GET' || $httpMethod === 'HEAD';
        }
        return true;
    }

    private function entryValue(mixed $entry, string $key, mixed $default = null): mixed
    {
        if (is_array($entry)) {
            return $entry[$key] ?? $default;
        }
        if (is_object($entry) && method_exists($entry, 'getData')) {
            $value = $entry->getData($key);
            return $value ?? $default;
        }
        return $default;
    }

    /**
     * @inheritDoc
     */
    public function isRouteProtected(string $routePath): bool
    {
        $routePath = self::normalizeRoutePath($routePath);
        if ($routePath === '') {
            return false;
        }
        self::registerStateManager();
        $state = self::requestState();
        if (array_key_exists($routePath, $state['route_protected'])) {
            return $state['route_protected'][$routePath];
        }
        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        $protected = false;
        foreach ($this->getEquivalentRoutePaths($routePath) as $candidate) {
            // Menu links may carry a deterministic query string (for example a
            // default website scope), while the router authorizes only the path.
            // Check both representations so a query-bearing menu row cannot turn
            // the underlying controller action into an implicit white route.
            foreach ([$candidate, $candidate . '?%'] as $storedRoute) {
                /** @var Acl $freshAcl */
                $freshAcl = ObjectManager::getInstance(Acl::class, [], false);
                $row = $freshAcl
                    ->where(
                        Acl::schema_fields_ROUTE,
                        $storedRoute,
                        str_ends_with($storedRoute, '?%') ? 'like' : '=',
                    )
                    ->limit(1)
                    ->find()
                    ->fetch();
                if ($row->getId()) {
                    $protected = true;
                    break 2;
                }
            }
        }
        if ($t0 > 0) {
            RequestLifecycleTrace::recordSpan('acl::AclService::isRouteProtected_db', (microtime(true) - $t0) * 1000, 'observer');
        }
        $state = self::requestState();
        $state['route_protected'][$routePath] = $protected;
        self::storeRequestState($state);
        return $protected;
    }

    /**
     * Router generation can expose several URL aliases for one controller action.
     * ACL is stored once per source_id, so every alias must share protection.
     *
     * @return array<int, string>
     */
    protected function getEquivalentRoutePaths(string $routePath): array
    {
        $routePath = self::normalizeRoutePath($routePath);
        if ($routePath === '') {
            return [];
        }
        self::registerStateManager();
        $state = self::requestState();
        if (isset($state['route_equivalent_paths'][$routePath])) {
            return $state['route_equivalent_paths'][$routePath];
        }

        $routes = $this->loadRouteRegistryEntries();
        $paths = [$routePath => true];
        $targets = [];
        foreach ($routes as $routeKey => $routeData) {
            if (self::normalizeRouteKeyPath((string)$routeKey) !== $routePath) {
                continue;
            }
            $signature = $this->routeControllerSignature((string)$routeKey, $routeData);
            if ($signature !== null) {
                $targets[$signature] = true;
            }
        }

        if (!empty($targets)) {
            foreach ($routes as $routeKey => $routeData) {
                $signature = $this->routeControllerSignature((string)$routeKey, $routeData);
                if ($signature === null || !isset($targets[$signature])) {
                    continue;
                }
                $candidate = self::normalizeRouteKeyPath((string)$routeKey);
                if ($candidate !== '') {
                    $paths[$candidate] = true;
                }
            }
        }

        $equivalentPaths = array_keys($paths);
        $state = self::requestState();
        $state['route_equivalent_paths'][$routePath] = $equivalentPaths;
        self::storeRequestState($state);
        return $equivalentPaths;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRouteRegistryEntries(): array
    {
        $routes = [];
        foreach ($this->getRouteRegistryFiles() as $routeFile) {
            $routeFile = (string)$routeFile;
            if ($routeFile === '' || !is_file($routeFile)) {
                continue;
            }
            $loaded = require $routeFile;
            if (is_array($loaded)) {
                $routes += $loaded;
            }
        }
        return $routes;
    }

    /**
     * @return array<int|string, string>
     */
    protected function getRouteRegistryFiles(): array
    {
        $hasBasePath = defined('Weline\Framework\App\BP') || defined('BP');
        $hasDirectorySeparator = defined('Weline\Framework\App\DS') || defined('DS');
        if (!$hasBasePath || !$hasDirectorySeparator) {
            return [];
        }

        return Env::router_files_PATH;
    }

    private static function normalizeRoutePath(string $routePath): string
    {
        $routePath = trim($routePath);
        $path = preg_split('/[?#]/', $routePath, 2)[0] ?? '';
        return strtolower(trim($path, '/'));
    }

    private static function normalizeRouteKeyPath(string $routeKey): string
    {
        [$path] = explode('::', $routeKey, 2);
        return self::normalizeRoutePath($path);
    }

    private function routeControllerSignature(string $routeKey, mixed $routeData): ?string
    {
        if (!is_array($routeData)) {
            return null;
        }

        $classData = $routeData['class'] ?? ($routeData['rule']['class'] ?? null);
        if (!is_array($classData)) {
            return null;
        }

        $className = strtolower(trim((string)($classData['name'] ?? '')));
        $methodName = strtolower(trim((string)($classData['method'] ?? '')));
        if ($className === '' || $methodName === '') {
            return null;
        }

        $httpMethod = strtoupper(trim((string)($classData['request_method'] ?? '')));
        if ($httpMethod === '') {
            [, $httpMethod] = array_pad(explode('::', $routeKey, 2), 2, '');
            $httpMethod = strtoupper(trim($httpMethod));
        }

        return $className . "\0" . $methodName . "\0" . $httpMethod;
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

    /**
     * @inheritDoc
     */
    public function isObjectActionAllowed(int $roleId, string $action, array $objectScope): bool
    {
        try {
            $identity = \Weline\Framework\Runtime\ScopeIdentity::fromArray($objectScope);
        } catch (\Throwable) {
            return false;
        }

        return $this->objectAuthorization()->isObjectActionAllowed($roleId, $action, $identity);
    }

    /**
     * @inheritDoc
     */
    public function isObjectActionAllowedForSubmit(
        int $roleId,
        string $action,
        array $objectScope,
        int $expectedGrantVersion,
    ): bool {
        try {
            $identity = \Weline\Framework\Runtime\ScopeIdentity::fromArray($objectScope);
        } catch (\Throwable) {
            return false;
        }

        return $this->objectAuthorization()
            ->authorizeForSubmit($roleId, $action, $identity, $expectedGrantVersion)
            ->allowed;
    }

    private function objectAuthorization(): \Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface
    {
        return ObjectManager::getInstance(
            \Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface::class
        );
    }
}
