<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook;

use Weline\Framework\Manager\ObjectManager;

/**
 * Hook 数据静态查询类
 * 提供各种 Hook 相关的查询功能，基于 generated/hooks.php 快速查询
 */
class HookData
{
    /**
     * 缓存的注册表数据
     */
    private static ?array $cachedRegistry = null;

    /**
     * 获取注册表数据（带缓存）
     *
     * @return array
     */
    private static function getRegistry(): array
    {
        if (self::$cachedRegistry === null) {
            /** @var HookRegistry $registry */
            $registry = ObjectManager::getInstance(HookRegistry::class);
            self::$cachedRegistry = $registry->getRegistry();
        }
        return self::$cachedRegistry;
    }

    /**
     * 获取 Hook 列表
     *
     * @return array
     */
    public static function getHooks(): array
    {
        $registry = self::getRegistry();
        return $registry['hooks'] ?? [];
    }

    /**
     * 获取 Hook 名到模块名的映射（快速查询）
     *
     * @return array 格式：['Weline_Theme::frontend::partials::footer::before' => 'Weline_Theme', ...]
     */
    public static function getHookToModuleMap(): array
    {
        $registry = self::getRegistry();
        return $registry['hook_to_module'] ?? [];
    }

    /**
     * 获取 Hook 所属的模块名
     *
     * @param string $hookName Hook 名
     * @return string|null 模块名，如果不存在返回 null
     */
    public static function getHookModule(string $hookName): ?string
    {
        /** @var HookRegistry $registry */
        $registry = ObjectManager::getInstance(HookRegistry::class);
        return $registry->getHookModule($hookName);
    }

    /**
     * 检查 Hook 是否存在
     *
     * @param string $hookName Hook 名
     * @return bool
     */
    public static function hookExists(string $hookName): bool
    {
        $hooks = self::getHooks();
        return isset($hooks[$hookName]);
    }

    /**
     * 检查 Hook 是否有规约
     *
     * @param string $hookName Hook 名
     * @return bool
     */
    public static function hasSpec(string $hookName): bool
    {
        /** @var HookRegistry $registry */
        $registry = ObjectManager::getInstance(HookRegistry::class);
        return $registry->hasSpec($hookName);
    }

    /**
     * 检查 Hook 是否有文档
     *
     * @param string $hookName Hook 名
     * @return bool
     */
    public static function hasDoc(string $hookName): bool
    {
        /** @var HookRegistry $registry */
        $registry = ObjectManager::getInstance(HookRegistry::class);
        return $registry->hasDoc($hookName);
    }

    /**
     * 获取 Hook 信息
     *
     * @param string $hookName Hook 名
     * @return array|null Hook 信息，如果不存在返回 null
     */
    public static function getHookInfo(string $hookName): ?array
    {
        /** @var HookRegistry $registry */
        $registry = ObjectManager::getInstance(HookRegistry::class);
        return $registry->getHookInfo($hookName);
    }

    /**
     * 获取 Hook 名称（显示名称）
     *
     * @param string $hookName Hook 名
     * @return string
     */
    public static function getHookDisplayName(string $hookName): string
    {
        $hookInfo = self::getHookInfo($hookName);
        return $hookInfo['name'] ?? $hookName;
    }

    /**
     * 获取 Hook 描述
     *
     * @param string $hookName Hook 名
     * @return string
     */
    public static function getHookDescription(string $hookName): string
    {
        $hookInfo = self::getHookInfo($hookName);
        return $hookInfo['description'] ?? '';
    }

    /**
     * 获取 Hook 文档路径
     *
     * @param string $hookName Hook 名
     * @return string|null
     */
    public static function getHookDocPath(string $hookName): ?string
    {
        $hookInfo = self::getHookInfo($hookName);
        return $hookInfo['doc_path'] ?? null;
    }

    /**
     * 获取模块定义的所有 Hook
     *
     * @param string $moduleName 模块名
     * @return array Hook 名列表
     */
    public static function getModuleHooks(string $moduleName): array
    {
        $hookToModule = self::getHookToModuleMap();
        $hooks = [];
        foreach ($hookToModule as $hookName => $module) {
            if ($module === $moduleName) {
                $hooks[] = $hookName;
            }
        }
        return $hooks;
    }

    /**
     * 获取所有模块列表（定义了 Hook 的模块）
     *
     * @return array 模块名列表
     */
    public static function getModules(): array
    {
        $hookToModule = self::getHookToModuleMap();
        return array_values(array_unique($hookToModule));
    }

    /**
     * 清除缓存（当注册表更新后调用）
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedRegistry = null;
    }
}
