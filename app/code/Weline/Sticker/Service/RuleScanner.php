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

/**
 * 规则扫描服务
 * 扫描所有模块的 extends/Weline_Sticker 目录
 */
class RuleScanner
{
    public const STICKER_DIR = 'extends' . DIRECTORY_SEPARATOR . 'Weline_Sticker';

    /**
     * 扫描所有模块的 extends/Weline_Sticker 目录
     *
     * @return array 返回格式：
     * [
     *   'source_module' => 'Weline_MyModule',
     *   'sticker_file' => 'Extends/Sticker/Weline/Demo/view/templates/Backend/index.phtml',
     *   'target_module' => 'Weline_Demo',
     *   'target_file' => 'Weline/Demo/view/templates/Backend/index.phtml'
     * ]
     */
    public function scanAllStickers(): array
    {
        $stickers = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            $stickerDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_DIR;

            if (!is_dir($stickerDir)) {
                continue;
            }

            // 扫描该模块的 extends/Weline_Sticker 目录
            $moduleStickers = $this->scanModuleStickers($moduleName, $basePath, $stickerDir);
            $stickers = array_merge($stickers, $moduleStickers);
        }

        return $stickers;
    }

    /**
     * 扫描单个模块的 Sticker 目录
     *
     * @param string $sourceModule 来源模块名
     * @param string $basePath 模块基础路径
     * @param string $stickerDir Sticker 目录路径
     * @return array
     */
    private function scanModuleStickers(string $sourceModule, string $basePath, string $stickerDir): array
    {
        $stickers = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($stickerDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $stickerFilePath = $file->getPathname();
                $relativePath = str_replace($stickerDir . DIRECTORY_SEPARATOR, '', $stickerFilePath);
                $relativePath = str_replace('\\', '/', $relativePath);

                // 从路径中提取目标模块和文件路径
                // 例如：Weline/Demo/view/templates/Backend/index.phtml
                $targetInfo = $this->parseTargetFromPath($relativePath);

                if ($targetInfo) {
                    $stickers[] = [
                        'source_module' => $sourceModule,
                        'source_base_path' => $basePath,
                        'sticker_file' => $stickerFilePath,
                        'sticker_relative_path' => $relativePath,
                        'target_module' => $targetInfo['target_module'],
                        'target_file' => $targetInfo['target_file']
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("扫描模块 Sticker 失败: {$sourceModule}, 错误: " . $e->getMessage());
        }

        return $stickers;
    }

    /**
     * 从路径中解析目标模块和文件路径
     *
     * @param string $relativePath 相对路径，例如：Weline/Demo/view/templates/Backend/index.phtml
     * @return array|null ['target_module' => 'Weline_Demo', 'target_file' => 'Weline/Demo/view/templates/Backend/index.phtml']
     */
    private function parseTargetFromPath(string $relativePath): ?array
    {
        // 路径格式：{Vendor}/{Module}/{...文件路径...}
        $parts = explode('/', $relativePath);
        if (count($parts) < 3) {
            return null;
        }

        $vendor = $parts[0];
        $module = $parts[1];
        $targetModule = $vendor . '_' . $module;

        return [
            'target_module' => $targetModule,
            'target_file' => $relativePath
        ];
    }

    /**
     * 检查模块是否有 Sticker 文件
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function hasStickers(string $moduleName): bool
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$moduleName])) {
            return false;
        }

        $module = $modules[$moduleName];
        $basePath = $module['base_path'] ?? '';
        if (empty($basePath)) {
            return false;
        }

        $stickerDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_DIR;
        return is_dir($stickerDir);
    }
}

