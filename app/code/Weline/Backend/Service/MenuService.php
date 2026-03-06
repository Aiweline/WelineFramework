<?php
declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Acl\Model\Role;
use Weline\Acl\Service\AclService;
use Weline\Acl\Service\AclServiceInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;

class MenuService implements MenuServiceInterface
{
    private Menu $menuModel;
    private AclServiceInterface $aclService;
    private Role $roleModel;

    public function __construct(
        Menu       $menuModel,
        AclService $aclService,
        Role       $roleModel
    ) {
        $this->menuModel = $menuModel;
        $this->aclService = $aclService;
        $this->roleModel = $roleModel;
    }

    public function getMenuTreeByRoleId(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
        if (!$role->getId()) {
            return [];
        }
        return $this->menuModel->getMenuTreeByRole($role);
    }

    public function getMenuTreeByUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        /** @var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class, [], false)->load($userId);
        if (!$user->getId()) {
            return [];
        }
        $role = $user->getRoleModel();
        if (!$role->getId()) {
            return [];
        }
        return $this->menuModel->getMenuTreeByRole($role);
    }

    public function hasMenuEntry(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }
        // 先基于 ACL 粗判是否有 menus 类型权限
        if (!$this->aclService->hasMenuPermission($roleId)) {
            return false;
        }
        // 再看是否能实际构建出菜单树
        $tree = $this->getMenuTreeByRoleId($roleId);
        return !empty($tree);
    }

    public function getDefaultEntryRoute(int $roleId): ?string
    {
        // 1. 优先从菜单树中选择第一个可点击菜单路由
        $tree = $this->getMenuTreeByRoleId($roleId);
        if (!empty($tree)) {
            $node = $this->findFirstClickableNode($tree);
            if ($node !== null) {
                $route = $node['route'] ?? '';
                if ($route !== '') {
                    return trim((string)$route, '/');
                }
            }
        }

        // 2. 若没有菜单入口，但 ACL 中存在可访问后台路由，则退回到 ACL 提供的默认路由
        $fallbackRoute = $this->aclService->getDefaultRouteFromAcl($roleId);
        return $fallbackRoute !== null && $fallbackRoute !== '' ? trim($fallbackRoute, '/') : null;
    }

    public function findMenuNodeByRoute(int $roleId, string $routePath): ?array
    {
        $routePath = trim($routePath, '/');
        if ($routePath === '') {
            return null;
        }
        $tree = $this->getMenuTreeByRoleId($roleId);
        if (empty($tree)) {
            return null;
        }
        return $this->searchNodeByRoute($tree, $routePath);
    }

    /**
     * 在菜单树中按 DFS 顺序找到第一个“可点击”的节点（有 route）。
     *
     * @param array $nodes
     * @return array|null
     */
    private function findFirstClickableNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            $route = $node['route'] ?? $node[Menu::schema_fields_ACTION] ?? '';
            $route = trim((string)$route, '/');
            if ($route !== '') {
                return $node;
            }
            if (!empty($node['nodes']) && is_array($node['nodes'])) {
                $found = $this->findFirstClickableNode($node['nodes']);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * 在菜单树中按 routePath 搜索节点。
     *
     * @param array $nodes
     * @param string $routePath
     * @return array|null
     */
    private function searchNodeByRoute(array $nodes, string $routePath): ?array
    {
        foreach ($nodes as $node) {
            $route = $node['route'] ?? $node[Menu::schema_fields_ACTION] ?? '';
            $route = trim((string)$route, '/');
            if ($route === $routePath) {
                return $node;
            }
            if (!empty($node['nodes']) && is_array($node['nodes'])) {
                $found = $this->searchNodeByRoute($node['nodes'], $routePath);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}

