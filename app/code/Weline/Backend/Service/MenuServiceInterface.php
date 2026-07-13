<?php
declare(strict_types=1);

namespace Weline\Backend\Service;

/**
 * 统一封装角色/用户的菜单树、默认入口路由与菜单权限检测能力。
 *
 * - 上层（拦截器、登录跳转、菜单渲染）只依赖本接口，不直接依赖 Menu/Acl/RoleAccess 模型
 */
interface MenuServiceInterface extends \Weline\Backend\Api\Menu\MenuReaderInterface
{
    /**
     * 根据角色 ID 获取菜单树（用于左侧导航渲染）。
     *
     * @param int $roleId
     * @return array
     */
    public function getMenuTreeByRoleId(int $roleId): array;

    /**
     * 根据用户 ID 获取菜单树（封装 user->role 映射逻辑）。
     *
     * @param int $userId
     * @return array
     */
    public function getMenuTreeByUserId(int $userId): array;

    /**
     * 角色是否至少有一个菜单入口（基于 type=menus 且菜单启用）。
     *
     * @param int $roleId
     * @return bool
     */
    public function hasMenuEntry(int $roleId): bool;

    /**
     * 计算角色登录后的默认入口路由，例如 admin/dashboard 或第一个叶子菜单。
     *
     * 策略：
     * - 优先使用菜单树中的第一个可点击菜单路由
     * - 若角色没有菜单权限，但 ACL 中存在可访问的后台路由，则退回到 ACL 中的默认路由
     *
     * 返回的是不带前后斜杠的路由路径（如 admin/system/menus），由调用方决定是否拼接前缀。
     *
     * @param int $roleId
     * @return string|null
     */
    public function getDefaultEntryRoute(int $roleId): ?string;

    /**
     * 给定当前 routePath，返回对应的菜单节点（用于高亮/统计）。
     *
     * @param int $roleId
     * @param string $routePath
     * @return array|null
     */
    public function findMenuNodeByRoute(int $roleId, string $routePath): ?array;
}
