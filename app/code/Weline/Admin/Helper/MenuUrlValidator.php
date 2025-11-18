<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Helper;

use Weline\Backend\Model\Menu;
use Weline\Framework\Cache\CacheInterface;
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
        /** @var \Weline\Framework\Cache\CacheFactoryInterface $cacheFactory */
        $cacheFactory = ObjectManager::getInstance(\Weline\Admin\Cache\AdminCache::class);
        /** @var CacheInterface $cache */
        $cache = $cacheFactory->create();
        $cachedPaths = $cache->get(self::CACHE_KEY);

        if ($cachedPaths !== false && is_array($cachedPaths)) {
            self::$menuPathsCache = $cachedPaths;
            return in_array($routePath, $cachedPaths, true);
        }

        // 从数据库查询所有启用的后端菜单路径
        /** @var Menu $menuModel */
        $menuModel = ObjectManager::getInstance(Menu::class);
        $menus = $menuModel
            ->where(Menu::fields_IS_BACKEND, 1)
            ->where(Menu::fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();

        $menuPaths = [];
        foreach ($menus as $menu) {
            $action = $menu[Menu::fields_ACTION] ?? '';
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
        /** @var \Weline\Framework\Cache\CacheFactoryInterface $cacheFactory */
        $cacheFactory = ObjectManager::getInstance(\Weline\Admin\Cache\AdminCache::class);
        // CacheFactory需要调用create()方法获取CacheInterface实例
        /** @var CacheInterface $cache */
        $cache = $cacheFactory->create();
        $cache->delete(self::CACHE_KEY);
    }
}

