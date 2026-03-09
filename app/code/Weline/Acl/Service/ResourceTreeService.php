<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\AclNode;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;

/**
 * ACL 资源树服务
 * 
 * 统一的资源树构建器，用于：
 * - 后台运行时菜单渲染
 * - ACL 权限分配树
 * 
 * 数据源：仅从 weline_acl 表读取
 */
class ResourceTreeService implements ResourceTreeServiceInterface
{
    /**
     * 获取后台菜单树（运行时使用）
     * 
     * @param Role $role 角色
     * @return array 菜单树
     */
    public function getBackendMenuTree(Role $role): array
    {
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        
        // 获取所有启用的菜单类型资源
        $menuSources = $aclModel->reset()
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->order(Acl::schema_fields_ORDER, 'ASC')
            ->select()
            ->fetchArray();
        
        if (empty($menuSources)) {
            return [];
        }
        
        $menuSourceIds = array_column($menuSources, Acl::schema_fields_SOURCE_ID);
        
        // 非超管需要检查权限
        if ($role->getId() !== 1) {
            $allowedSources = $this->getAllowedMenuSources($role, $menuSourceIds);
            if (empty($allowedSources)) {
                return [];
            }
            // 展开祖先以确保树结构完整
            $allowedSources = $this->expandWithAncestors($menuSources, $allowedSources);
        } else {
            $allowedSources = $menuSourceIds;
        }
        
        // 构建树
        return $this->buildTree($menuSources, $allowedSources, '', true);
    }
    
    /**
     * 获取 ACL 权限分配树（包含菜单和 pc 类型）
     * 
     * 使用 fetchArray() + AclNode 替代 fetch()->getItems()，
     * 避免为每行创建完整 Acl Model（含反射/Schema 解析），提升约 100 倍构造速度。
     * 
     * @param Role $role 角色
     * @return AclNode[] 权限树
     */
    public function getAclAssignmentTree(Role $role): array
    {
        $roleId = (int) $role->getId();
        
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $allRows = $aclModel->reset()
            ->order(Acl::schema_fields_PARENT_SOURCE, 'ASC')
            ->order(Acl::schema_fields_ORDER, 'ASC')
            ->select()
            ->fetchArray();
        
        $roleSelectedSources = $this->getRoleSelectedSources($roleId);
        
        // 按 parent_source 分组（使用轻量 AclNode）
        $byParent = ['' => []];
        foreach ($allRows as $row) {
            $sid = (string) ($row[Acl::schema_fields_SOURCE_ID] ?? '');
            $row['role_id'] = isset($roleSelectedSources[$sid]) ? true : null;
            $node = new AclNode($row);
            
            $parent = $node->getParentSource();
            $byParent[$parent][] = $node;
        }
        foreach ($byParent as $p => $list) {
            usort($byParent[$p], static fn(AclNode $a, AclNode $b) => $a->getOrder() <=> $b->getOrder());
        }
        
        return $this->buildAclTreeRecursive('', $byParent);
    }
    
    /**
     * 获取角色有权限的菜单 source_id 列表
     * 
     * @param Role $role
     * @param array $menuSourceIds
     * @return array
     */
    private function getAllowedMenuSources(Role $role, array $menuSourceIds): array
    {
        $roleAccessModel = ObjectManager::getInstance(RoleAccess::class, [], false);
        $roleAccess = $roleAccessModel->clear()
            ->where(RoleAccess::schema_fields_ROLE_ID, $role->getId(0))
            ->select()
            ->fetchArray();
        
        $roleAccessSources = array_column($roleAccess, RoleAccess::schema_fields_SOURCE_ID);
        
        return array_intersect($roleAccessSources, $menuSourceIds);
    }
    
    /**
     * 展开祖先节点
     * 
     * @param array $allSources 所有资源
     * @param array $allowedSources 允许的资源
     * @return array
     */
    private function expandWithAncestors(array $allSources, array $allowedSources): array
    {
        $parentMap = [];
        foreach ($allSources as $row) {
            $sid = $row[Acl::schema_fields_SOURCE_ID] ?? '';
            $pid = $row[Acl::schema_fields_PARENT_SOURCE] ?? '';
            if ($sid !== '' && $pid !== '') {
                $parentMap[$sid] = $pid;
            }
        }
        
        $expanded = array_flip($allowedSources);
        foreach ($allowedSources as $sid) {
            $current = $parentMap[$sid] ?? '';
            while ($current !== '' && $current !== null) {
                $expanded[$current] = true;
                $current = $parentMap[$current] ?? '';
            }
        }
        
        return array_keys($expanded);
    }
    
    /**
     * 构建菜单树
     * 
     * @param array $allSources 所有资源
     * @param array $allowedSources 允许的资源
     * @param string $parentSource 父级
     * @param bool $filterMenuType 是否过滤只保留菜单类型
     * @return array
     */
    private function buildTree(array $allSources, array $allowedSources, string $parentSource, bool $filterMenuType): array
    {
        $nodes = [];
        $allowedSet = array_flip($allowedSources);
        
        foreach ($allSources as $source) {
            $sid = $source[Acl::schema_fields_SOURCE_ID] ?? '';
            $parent = $source[Acl::schema_fields_PARENT_SOURCE] ?? '';
            $type = $source[Acl::schema_fields_TYPE] ?? '';
            
            // 匹配父级
            if ($parent !== $parentSource) {
                continue;
            }
            
            // 检查是否在允许列表
            if (!isset($allowedSet[$sid])) {
                continue;
            }
            
            // 如果过滤菜单类型，跳过非菜单
            if ($filterMenuType && $type !== Acl::type_MENUS) {
                continue;
            }
            
            $node = [
                'source_id' => $sid,
                'source_name' => $source[Acl::schema_fields_SOURCE_NAME] ?? '',
                'type' => $type,
                'icon' => $source[Acl::schema_fields_ICON] ?? '',
                'route' => $source[Acl::schema_fields_ROUTE] ?? '',
                'module' => $source[Acl::schema_fields_MODULE] ?? '',
                'order' => (int)($source[Acl::schema_fields_ORDER] ?? 0),
                'is_enable' => (int)($source[Acl::schema_fields_IS_ENABLE] ?? 1),
                'is_backend' => (int)($source[Acl::schema_fields_IS_BACKEND] ?? 1),
            ];
            
            // 递归构建子节点
            $children = $this->buildTree($allSources, $allowedSources, $sid, $filterMenuType);
            if (!empty($children)) {
                $node['nodes'] = $children;
            }
            
            $nodes[] = $node;
        }
        
        return $nodes;
    }
    
    /**
     * 获取角色已选权限
     * 
     * @param int $roleId
     * @return array
     */
    private function getRoleSelectedSources(int $roleId): array
    {
        $roleAccessModel = ObjectManager::getInstance(RoleAccess::class, [], false);
        $rows = $roleAccessModel->clear()
            ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
            ->select()
            ->fetchArray();
        
        $selected = [];
        foreach ($rows as $row) {
            $sid = $row[RoleAccess::schema_fields_SOURCE_ID] ?? '';
            if ($sid !== '') {
                $selected[$sid] = true;
            }
        }
        
        return $selected;
    }
    
    /**
     * 递归构建 ACL 树
     * 
     * @param string $parentSource
     * @param array<string, AclNode[]> $aclByParent
     * @return AclNode[]
     */
    private function buildAclTreeRecursive(string $parentSource, array $aclByParent): array
    {
        $candidates = $aclByParent[$parentSource] ?? [];
        $nodes = [];
        
        foreach ($candidates as $node) {
            $sid = $node->getSourceId();
            if ($sid === '') {
                continue;
            }
            
            $children = $this->buildAclTreeRecursive($sid, $aclByParent);
            $node->setData('sub', $children);
            $nodes[] = $node;
        }
        
        return $nodes;
    }
    
    /**
     * 获取所有启用的后台菜单路由列表
     * 
     * @return array
     */
    public function getEnabledBackendMenuRoutes(): array
    {
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $menus = $aclModel->reset()
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_IS_BACKEND, 1)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();
        
        $routes = [];
        foreach ($menus as $menu) {
            $route = $menu[Acl::schema_fields_ROUTE] ?? '';
            if ($route !== '') {
                $route = trim($route, '/');
                if ($route !== '') {
                    $routes[] = $route;
                }
            }
        }
        
        return $routes;
    }
    
    /**
     * 根据 ID 加载 ACL 菜单资源
     * 
     * @param int|string $id acl_id 或 source_id
     * @return Acl|null
     */
    public function loadMenuResource(int|string $id): ?Acl
    {
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        
        // 尝试按 acl_id 加载
        if (is_numeric($id) && $id > 0) {
            $acl = $aclModel->load((int) $id, Acl::schema_fields_ACL_ID);
            if ($acl->getSourceId()) {
                return $acl;
            }
        }
        
        // 尝试按 source_id 加载
        $acl = $aclModel->load((string) $id, Acl::schema_fields_SOURCE_ID);
        if ($acl->getSourceId()) {
            return $acl;
        }
        
        return null;
    }
    
    /**
     * 检查菜单资源是否有子节点
     * 
     * @param string $sourceId
     * @return bool
     */
    public function hasMenuChildren(string $sourceId): bool
    {
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $child = $aclModel->reset()
            ->where(Acl::schema_fields_PARENT_SOURCE, $sourceId)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->find()
            ->fetch();
        
        return !empty($child->getSourceId());
    }
    
    /**
     * 获取所有菜单资源（用于后台管理列表）
     * 
     * @return array
     */
    public function getAllMenuResources(): array
    {
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        return $aclModel->reset()
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->order(Acl::schema_fields_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 构建菜单树形结构（用于后台管理页面）
     * 
     * @param array $menus 所有菜单
     * @param string $parentSource
     * @return array
     */
    public function buildMenuManagementTree(array $menus, string $parentSource = ''): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if (($menu[Acl::schema_fields_PARENT_SOURCE] ?? '') === $parentSource) {
                $sourceId = $menu[Acl::schema_fields_SOURCE_ID] ?? '';
                $children = $this->buildMenuManagementTree($menus, $sourceId);
                $menu['children'] = $children;
                $menu['has_children'] = !empty($children);
                $tree[] = $menu;
            }
        }
        
        usort($tree, fn($a, $b) => (($a[Acl::schema_fields_ORDER] ?? 0) <=> ($b[Acl::schema_fields_ORDER] ?? 0)));
        return $tree;
    }
}
