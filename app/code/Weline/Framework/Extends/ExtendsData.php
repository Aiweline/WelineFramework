<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends;

/**
 * 扩展数据读取器（静态类）
 * 提供静态方法读取 generated/extends.php 文件中的数据
 * 方便其他模块（如 Weline_Sticker）直接使用，无需再次扫描
 */
class ExtendsData
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'extends.php';

    /**
     * 静态缓存
     *
     * @var array|null
     */
    private static ?array $cachedRegistry = null;

    /**
     * 缓存文件的修改时间
     *
     * @var int|null
     */
    private static ?int $cachedFileMtime = null;

    /**
     * 获取完整的注册表数据
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getRegistry(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && self::$cachedRegistry !== null) {
            $currentMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            if ($currentMtime === self::$cachedFileMtime) {
                return self::$cachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            self::$cachedRegistry = [];
            self::$cachedFileMtime = 0;
            return [];
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = [];
        }

        self::$cachedRegistry = $registry;
        self::$cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 获取指定模块的完整数据
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array 返回格式：
     * [
     *   'extends' => [...],           // 模块定义的扩展点
     *   'extended_by' => [...],       // 被其他模块扩展的信息
     *   'completeness' => [...],      // 完备性检查信息
     *   'enhanced_extensions' => [...], // 增强的扩展信息
     *   'stats' => [...]              // 统计信息
     * ]
     */
    public static function getModuleData(string $moduleName, bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        return $registry[$moduleName] ?? [];
    }

    /**
     * 获取模块定义的扩展点信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getModuleExtends(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        return $moduleData['extends'] ?? [];
    }

    /**
     * 获取扩展该模块的其他模块信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array 返回格式：['Weline_MyModule' => [...扩展信息...]]
     */
    public static function getExtendedBy(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        return $moduleData['extended_by'] ?? [];
    }

    /**
     * 获取模块的 Sticker 扩展信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getStickerExtensions(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        $extendedBy = $moduleData['extended_by'] ?? [];
        $stickerExtensions = [];
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    $stickerExtensions[] = $extension;
                }
            }
        }
        
        return $stickerExtensions;
    }

    /**
     * 获取模块的普通模块扩展信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getModuleExtensions(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        $extendedBy = $moduleData['extended_by'] ?? [];
        $moduleExtensions = [];
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) !== true && 
                    ($extension['type'] ?? '') === 'module') {
                    $moduleExtensions[] = $extension;
                }
            }
        }
        
        return $moduleExtensions;
    }

    /**
     * 获取模块的主题扩展信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getThemeExtensions(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        $extendedBy = $moduleData['extended_by'] ?? [];
        $themeExtensions = [];
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) !== true && 
                    ($extension['type'] ?? '') === 'theme') {
                    $themeExtensions[] = $extension;
                }
            }
        }
        
        return $themeExtensions;
    }

    /**
     * 获取模块的统计信息（已简化，不再使用）
     *
     * @deprecated 数据结构已简化，不再包含 stats
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getModuleStats(string $moduleName, bool $forceReload = false): array
    {
        // 不再返回统计信息，保持数据结构简洁
        return [];
    }

    /**
     * 获取模块的完备性检查信息
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getCompleteness(string $moduleName, bool $forceReload = false): array
    {
        $moduleData = self::getModuleData($moduleName, $forceReload);
        return $moduleData['completeness'] ?? [];
    }

    /**
     * 检查模块是否有扩展定义
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return bool
     */
    public static function hasExtends(string $moduleName, bool $forceReload = false): bool
    {
        $extends = self::getModuleExtends($moduleName, $forceReload);
        return !empty($extends);
    }

    /**
     * 检查模块是否被其他模块扩展
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return bool
     */
    public static function isExtendedBy(string $moduleName, bool $forceReload = false): bool
    {
        $extendedBy = self::getExtendedBy($moduleName, $forceReload);
        return !empty($extendedBy);
    }

    /**
     * 检查模块是否被 Sticker 扩展
     *
     * @param string $moduleName 模块名
     * @param bool $forceReload 强制重新加载
     * @return bool
     */
    public static function isStickerExtended(string $moduleName, bool $forceReload = false): bool
    {
        $stickerExtensions = self::getStickerExtensions($moduleName, $forceReload);
        return !empty($stickerExtensions);
    }

    /**
     * 获取所有模块名列表
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getAllModuleNames(bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        return array_keys($registry);
    }

    /**
     * 获取所有有扩展定义的模块名列表
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getModulesWithExtends(bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        $modules = [];
        
        foreach ($registry as $moduleName => $data) {
            if (!empty($data['extends'] ?? [])) {
                $modules[] = $moduleName;
            }
        }
        
        return $modules;
    }

    /**
     * 获取所有被扩展的模块名列表
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public static function getExtendedModules(bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        $modules = [];
        
        foreach ($registry as $moduleName => $data) {
            if (!empty($data['extended_by'] ?? [])) {
                $modules[] = $moduleName;
            }
        }
        
        return $modules;
    }

    /**
     * 获取所有 Sticker 扩展信息（跨模块）
     *
     * @param bool $forceReload 强制重新加载
     * @return array 返回格式：['Weline_Sticker' => [...扩展信息...]]
     */
    public static function getAllStickerExtensions(bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        $stickerExtensions = [];
        
        foreach ($registry as $moduleName => $data) {
            $extendedBy = $data['extended_by'] ?? [];
            
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        if (!isset($stickerExtensions[$sourceModule])) {
                            $stickerExtensions[$sourceModule] = [];
                        }
                        $stickerExtensions[$sourceModule][] = array_merge($extension, [
                            'target_module' => $moduleName
                        ]);
                    }
                }
            }
        }
        
        return $stickerExtensions;
    }

    /**
     * 根据源模块获取其扩展的所有目标模块
     *
     * @param string $sourceModule 源模块名（如 Weline_Sticker）
     * @param bool $forceReload 强制重新加载
     * @return array 返回格式：['Weline_Demo' => [...扩展信息...]]
     */
    public static function getExtensionsBySourceModule(string $sourceModule, bool $forceReload = false): array
    {
        $registry = self::getRegistry($forceReload);
        $result = [];
        
        foreach ($registry as $moduleName => $data) {
            $extendedBy = $data['extended_by'] ?? [];
            if (isset($extendedBy[$sourceModule])) {
                $result[$moduleName] = $extendedBy[$sourceModule];
            }
        }
        
        return $result;
    }

    /**
     * 根据文件路径查找扩展信息
     *
     * @param string $filePath 文件路径（相对路径或绝对路径）
     * @param bool $forceReload 强制重新加载
     * @return array|null 找到的扩展信息，未找到返回 null
     */
    public static function findExtensionByFilePath(string $filePath, bool $forceReload = false): ?array
    {
        $registry = self::getRegistry($forceReload);
        
        // 标准化路径
        $normalizedPath = str_replace('\\', '/', $filePath);
        
        foreach ($registry as $moduleName => $data) {
            $extendedBy = $data['extended_by'] ?? [];
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    $sourceFile = str_replace('\\', '/', $extension['source_file'] ?? '');
                    $relativePath = str_replace('\\', '/', $extension['relative_path'] ?? '');
                    $filePathInExtension = str_replace('\\', '/', $extension['file_path'] ?? '');
                    
                    if ($sourceFile === $normalizedPath 
                        || $relativePath === $normalizedPath 
                        || $filePathInExtension === $normalizedPath
                        || str_contains($sourceFile, $normalizedPath)
                        || str_contains($relativePath, $normalizedPath)
                        || str_contains($filePathInExtension, $normalizedPath)
                    ) {
                        return array_merge($extension, [
                            'target_module' => $moduleName,
                            'source_module' => $sourceModule
                        ]);
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * 清除静态缓存
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedRegistry = null;
        self::$cachedFileMtime = null;
    }

    /**
     * 检查注册表文件是否存在
     *
     * @return bool
     */
    public static function registryFileExists(): bool
    {
        return file_exists(self::REGISTRY_FILE);
    }

    /**
     * 获取注册表文件的修改时间
     *
     * @return int 文件修改时间戳，文件不存在返回 0
     */
    public static function getRegistryFileMtime(): int
    {
        if (!file_exists(self::REGISTRY_FILE)) {
            return 0;
        }
        return filemtime(self::REGISTRY_FILE);
    }
}

