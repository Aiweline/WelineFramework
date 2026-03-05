<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Helper;

use Weline\Acl\Model\WhiteAclSource;
use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;

class MenuUrlValidator
{
    /**
     * 缓存键名
     */
    private const CACHE_KEY = 'backend_menu_paths';

    /**
     * 静态缓存，避免重复查询
     */
    private static ?array $menuPathsCache = null;

    /**
     * 验证URL路径是否是后端菜单链接
     *
     * @param string $routePath 路由路径（如：admin/system/menus）
     * @return bool
     */
    public static function isMenuUrl(string $routePath): bool
    {
        // 如果缓存已加载，直接使用
        if (self::$menuPathsCache !== null) {
            return in_array($routePath, self::$menuPathsCache, true);
        }

        // 尝试从缓存获取
        $cache = w_cache('default');
        $cachedPaths = $cache->get(self::CACHE_KEY);

        if ($cachedPaths !== false && is_array($cachedPaths)) {
            self::$menuPathsCache = $cachedPaths;
            return in_array($routePath, $cachedPaths, true);
        }

        // 从数据库查询所有启用的后端菜单路径
        /** @var Menu $menuModel */
        $menuModel = ObjectManager::getInstance(Menu::class);
        $menus = $menuModel
            ->where(Menu::schema_fields_IS_BACKEND, 1)
            ->where(Menu::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();

        $menuPaths = [];
        foreach ($menus as $menu) {
            $action = $menu[Menu::schema_fields_ACTION] ?? '';
            if ($action) {
                // 去除前后斜杠，统一格式
                $action = trim($action, '/');
                if ($action) {
                    $menuPaths[] = $action;
                }
            }
        }

        // 存储到静态缓存和文件缓存
        self::$menuPathsCache = $menuPaths;
        $cache->set(self::CACHE_KEY, $menuPaths, 3600); // 缓存1小时

        return in_array($routePath, $menuPaths, true);
    }

    /**
     * 清除菜单路径缓存
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$menuPathsCache = null;
        self::$whitelistCache = null;
        $cache = w_cache('default');
        $cache->delete(self::CACHE_KEY);
        $cache->delete(self::WHITELIST_CACHE_KEY);
    }

    /**
     * 白名单缓存键名
     */
    private const WHITELIST_CACHE_KEY = 'backend_acl_whitelist_paths';

    /**
     * 白名单静态缓存
     */
    private static ?array $whitelistCache = null;

    /**
     * 获取 ACL 白名单路径列表
     *
     * @return array
     */
    public static function getWhitelistPaths(): array
    {
        if (self::$whitelistCache !== null) {
            return self::$whitelistCache;
        }

        $cache = w_cache('default');
        $cachedPaths = $cache->get(self::WHITELIST_CACHE_KEY);

        if ($cachedPaths !== false && is_array($cachedPaths)) {
            self::$whitelistCache = $cachedPaths;
            return $cachedPaths;
        }

        // 从数据库查询白名单路径
        /** @var WhiteAclSource $whiteAclSource */
        $whiteAclSource = ObjectManager::getInstance(WhiteAclSource::class);
        $whitelist = $whiteAclSource
            ->fields('path')
            ->where('type', WhiteAclSource::type_PC)
            ->select()
            ->fetchArray();

        // fetchArray() 可能返回空数组或 null
        $paths = is_array($whitelist) ? array_column($whitelist, 'path') : [];
        
        self::$whitelistCache = $paths;
        $cache->set(self::WHITELIST_CACHE_KEY, $paths, 3600);

        return $paths;
    }

    /**
     * 检查路径是否在白名单中
     *
     * @param string $routePath 路由路径
     * @return bool
     */
    public static function isWhitelistPath(string $routePath): bool
    {
        $whitelist = self::getWhitelistPaths();
        return in_array($routePath, $whitelist, true);
    }

    /**
     * 检查路径是否可以作为登录后跳转的有效目标
     * 
     * 有效条件：
     * 1. 是菜单链接
     * 2. 不在白名单中（白名单路径本身不需要登录，跳转回去无意义）
     *
     * @param string $routePath 路由路径（如：admin/system/menus）
     * @return bool
     */
    public static function isValidLoginRedirectTarget(string $routePath): bool
    {
        // 必须是菜单链接
        if (!self::isMenuUrl($routePath)) {
            return false;
        }

        // 不能在白名单中（白名单路径不需要登录，跳转回去无意义）
        if (self::isWhitelistPath($routePath)) {
            return false;
        }

        return true;
    }
}

