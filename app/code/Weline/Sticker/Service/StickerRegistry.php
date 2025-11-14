<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;

/**
 * Sticker 注册表管理服务
 * 从 ExtendsData 读取数据，不再扫描文件系统
 */
class StickerRegistry
{
    private ?array $cachedRegistry = null;
    private ?int $cachedExtendsMtime = null;

    /**
     * 获取注册表内容
     * 从 ExtendsData 读取数据并转换为 StickerRegistry 格式
     *
     * @param bool $forceReload 强制重新加载
     * @return array 返回格式：[$targetModule][$targetFile] = [...sticker信息...]
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedRegistry !== null) {
            $currentMtime = ExtendsData::getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedRegistry;
            }
        }

        // 从 ExtendsData 读取所有 Sticker 扩展信息
        $allStickerExtensions = ExtendsData::getAllStickerExtensions($forceReload);
        
        // 转换为 StickerRegistry 格式
        $registry = $this->convertFromExtendsData($allStickerExtensions);

        $this->cachedRegistry = $registry;
        $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();

        return $registry;
    }

    /**
     * 将 ExtendsData 格式转换为 StickerRegistry 格式
     *
     * @param array $allStickerExtensions ExtendsData 格式的 Sticker 扩展数据
     * @return array StickerRegistry 格式的注册表数据
     */
    private function convertFromExtendsData(array $allStickerExtensions): array
    {
        $registry = [];

        foreach ($allStickerExtensions as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                $targetModule = $extension['target_module'] ?? '';
                
                // 优先使用 file_path，如果为空则从 relative_path 中提取
                $targetFile = $extension['file_path'] ?? '';
                if (empty($targetFile)) {
                    $relativePath = $extension['relative_path'] ?? '';
                    if (!empty($relativePath)) {
                        // 从 relative_path 中提取目标文件路径
                        // 格式：extends/module/Weline_Sticker/Weline/Sticker/view/templates/...
                        // 或：extends/theme/{ThemeName}/Weline_Sticker/Weline/Sticker/view/templates/...
                        $pathParts = explode('/', $relativePath);
                        
                        // 检查是否是 Sticker 扩展
                        $isStickerExtension = ($extension['is_sticker_extension'] ?? false) === true;
                        
                        if ($isStickerExtension) {
                            // Sticker 扩展路径解析
                            if (count($pathParts) >= 5) {
                                // 查找 Weline_Sticker 的位置
                                $stickerPos = array_search('Weline_Sticker', $pathParts);
                                if ($stickerPos !== false && $stickerPos < count($pathParts) - 2) {
                                    // 跳过 extends/{type}/Weline_Sticker/{Vendor}/{Module}/
                                    // 或 extends/theme/{ThemeName}/Weline_Sticker/{Vendor}/{Module}/
                                    $targetFile = implode('/', array_slice($pathParts, $stickerPos + 3));
                                }
                            }
                        } elseif (count($pathParts) >= 3) {
                            // 普通扩展：跳过 extends/{type}/{ModuleName}/
                            $targetFile = implode('/', array_slice($pathParts, 3));
                        }
                    }
                }
                
                if (empty($targetModule) || empty($targetFile)) {
                    continue;
                }

                // 初始化结构
                if (!isset($registry[$targetModule])) {
                    $registry[$targetModule] = [];
                }
                if (!isset($registry[$targetModule][$targetFile])) {
                    $registry[$targetModule][$targetFile] = [];
                }

                // 获取源文件路径
                $sourceFile = $extension['source_file'] ?? '';
                if (empty($sourceFile)) {
                    // 尝试从模块路径构建
                    $modules = Env::getInstance()->getModuleList();
                    $moduleInfo = $modules[$sourceModule] ?? null;
                    if ($moduleInfo) {
                        $basePath = $moduleInfo['base_path'] ?? '';
                        $relativePath = $extension['relative_path'] ?? '';
                        if ($basePath && $relativePath) {
                            $sourceFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                        }
                    }
                }

                // 添加 Sticker 信息
                $registry[$targetModule][$targetFile][] = [
                    'source_module' => $sourceModule,
                    'sticker_file' => $sourceFile,
                    'sticker_relative_path' => $extension['relative_path'] ?? '',
                    'type' => $extension['sticker_type'] ?? $extension['type'] ?? 'module',
                    'theme_name' => $extension['theme_name'] ?? null,
                    // 注意：actions 需要从文件解析，这里先留空，由 RuleParser 解析
                    'actions' => []
                ];
            }
        }

        return $registry;
    }

    /**
     * 保存注册表（已废弃，数据现在由 ExtendsRegistry 管理）
     * 
     * @deprecated 不再需要保存到 generated/sticker.php，数据由 ExtendsRegistry 统一管理
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        // 不再保存到文件，数据由 ExtendsRegistry 统一管理
        // 只更新内存缓存
        $this->cachedRegistry = $registry;
        return true;
    }

    /**
     * 检查文件是否有 Sticker
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @return bool
     */
    public function hasSticker(string $targetModule, string $targetFile): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$targetModule][$targetFile]) && 
               !empty($registry[$targetModule][$targetFile]);
    }

    /**
     * 获取文件的 Sticker 规则
     * 如果 actions 为空，会自动解析 Sticker 文件
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @param RuleParser|null $ruleParser 规则解析器（可选，如果提供会自动解析 actions）
     * @return array
     */
    public function getFileStickers(string $targetModule, string $targetFile, ?RuleParser $ruleParser = null): array
    {
        $registry = $this->getRegistry();
        $stickers = $registry[$targetModule][$targetFile] ?? [];
        
        // 如果提供了 RuleParser 且 actions 为空，则解析文件
        if ($ruleParser && !empty($stickers)) {
            foreach ($stickers as &$sticker) {
                if (empty($sticker['actions']) && !empty($sticker['sticker_file']) && file_exists($sticker['sticker_file'])) {
                    $sticker['actions'] = $ruleParser->parseStickerFile($sticker['sticker_file']);
                }
            }
            unset($sticker);
        }
        
        return $stickers;
    }

    /**
     * 检查模块是否有 Sticker（快速判断）
     *
     * @param string $targetModule 目标模块
     * @return bool
     */
    public function hasModuleStickers(string $targetModule): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$targetModule]) && !empty($registry[$targetModule]);
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->cachedRegistry = null;
        $this->cachedExtendsMtime = null;
        // 同时清除 ExtendsData 的缓存
        ExtendsData::clearCache();
    }

    /**
     * 从 ExtendsData 构建注册表（已废弃，现在直接从 ExtendsData 读取）
     * 
     * @deprecated 不再需要从扫描结果构建，数据现在由 ExtendsRegistry 统一管理
     * @param array $scannedStickers 扫描结果（已废弃）
     * @param RuleParser $ruleParser 规则解析器（可选，用于解析 actions）
     * @return array
     */
    public function buildRegistryFromScanned(array $scannedStickers, RuleParser $ruleParser): array
    {
        // 直接从 ExtendsData 读取数据
        $registry = $this->getRegistry(true);
        
        // 如果需要解析 actions，遍历并解析
        foreach ($registry as $targetModule => $files) {
            foreach ($files as $targetFile => $stickers) {
                foreach ($stickers as &$sticker) {
                    if (empty($sticker['actions']) && !empty($sticker['sticker_file']) && file_exists($sticker['sticker_file'])) {
                        $sticker['actions'] = $ruleParser->parseStickerFile($sticker['sticker_file']);
                    }
                }
                unset($sticker);
            }
        }
        
        return $registry;
    }
}

