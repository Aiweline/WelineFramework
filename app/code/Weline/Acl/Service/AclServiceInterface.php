<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

/**
 * 统一封装角色的 ACL 视图与权限判定逻辑。
 *
 * - 不关心菜单布局，仅关心“角色能访问哪些资源/路由/方法/type”
 * - 供 RouteBefore、菜单服务、调试工具等复用
 */
interface AclServiceInterface
{
    /**
     * 返回角色的所有 ACL 记录（含 route/method/type/module 等字段），用于调试或上层过滤。
     *
     * @param int $roleId
     * @return array
     */
    public function getRoleAclEntries(int $roleId): array;

    /**
     * 判断角色是否对给定路由+HTTP 方法有访问权限。
     *
     * @param int $roleId
     * @param string $routePath 规范化后的路由路径（不含前后斜杠）
     * @param string $httpMethod 大写 HTTP 方法，如 GET/POST
     * @return bool
     */
    public function isRouteAllowed(int $roleId, string $routePath, string $httpMethod): bool;

    /**
     * 角色是否至少拥有一条 ACL 记录（真正意义上的“有/无权限”粗判）。
     *
     * @param int $roleId
     * @return bool
     */
    public function hasAnyPermission(int $roleId): bool;

    /**
     * 角色是否至少拥有一个 type=menus 的 ACL 记录（是否拥有菜单型权限）。
     *
     * @param int $roleId
     * @return bool
     */
    public function hasMenuPermission(int $roleId): bool;

    /**
     * 返回角色可访问的 menus 类型 ACL 记录（仅包含轻量字段）。
     *
     * @param int $roleId
     * @return array
     */
    public function getMenuAclEntries(int $roleId): array;
}

