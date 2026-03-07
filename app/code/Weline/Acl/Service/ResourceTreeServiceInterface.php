<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;

/**
 * ACL 资源树服务接口
 */
interface ResourceTreeServiceInterface
{
    /**
     * 获取后台菜单树（运行时使用）
     * 
     * @param Role $role 角色
     * @return array 菜单树
     */
    public function getBackendMenuTree(Role $role): array;
    
    /**
     * 获取 ACL 权限分配树（包含菜单和 pc 类型）
     * 
     * @param Role $role 角色
     * @return array 权限树
     */
    public function getAclAssignmentTree(Role $role): array;
    
    /**
     * 获取所有启用的后台菜单路由列表
     * 
     * @return array
     */
    public function getEnabledBackendMenuRoutes(): array;
    
    /**
     * 根据 ID 加载 ACL 菜单资源
     * 
     * @param int|string $id acl_id 或 source_id
     * @return Acl|null
     */
    public function loadMenuResource(int|string $id): ?Acl;
    
    /**
     * 检查菜单资源是否有子节点
     * 
     * @param string $sourceId
     * @return bool
     */
    public function hasMenuChildren(string $sourceId): bool;
    
    /**
     * 获取所有菜单资源（用于后台管理列表）
     * 
     * @return array
     */
    public function getAllMenuResources(): array;
    
    /**
     * 构建菜单树形结构（用于后台管理页面）
     * 
     * @param array $menus 所有菜单
     * @param string $parentSource
     * @return array
     */
    public function buildMenuManagementTree(array $menus, string $parentSource = ''): array;
}
