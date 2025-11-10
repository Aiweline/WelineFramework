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
 * 扫描所有模块的 extends/module/Weline_Sticker 和 extends/theme/{ThemeName}/Weline_Sticker 目录
 */
class RuleScanner
{
    public const STICKER_MODULE_DIR = 'extends' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'Weline_Sticker';
    public const STICKER_THEME_DIR = 'extends' . DIRECTORY_SEPARATOR . 'theme';

    /**
     * 扫描所有模块的 Sticker 目录
     *
     * @return array 返回格式：
     * [
     *   'source_module' => 'Weline_MyModule',
     *   'sticker_file' => 'extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml',
     *   'target_module' => 'Weline_Demo',
     *   'target_file' => 'Weline/Demo/view/templates/Backend/index.phtml',
     *   'type' => 'module' // 或 'theme'
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

            // 扫描模块 Sticker 目录: extends/module/Weline_Sticker
            $stickerModuleDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_MODULE_DIR;
            if (is_dir($stickerModuleDir)) {
                $moduleStickers = $this->scanModuleStickers($moduleName, $basePath, $stickerModuleDir, 'module');
                $stickers = array_merge($stickers, $moduleStickers);
            }

            // 扫描主题 Sticker 目录: extends/theme/{ThemeName}/Weline_Sticker
            $stickerThemeDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_THEME_DIR;
            if (is_dir($stickerThemeDir)) {
                $themeStickers = $this->scanThemeStickers($moduleName, $basePath, $stickerThemeDir);
                $stickers = array_merge($stickers, $themeStickers);
            }
        }

        return $stickers;
    }

    /**
     * 扫描单个模块的 Sticker 目录
     *
     * @param string $sourceModule 来源模块名
     * @param string $basePath 模块基础路径
     * @param string $stickerDir Sticker 目录路径
     * @param string $type 类型 (module/theme)
     * @return array
     */
    private function scanModuleStickers(string $sourceModule, string $basePath, string $stickerDir, string $type = 'module'): array
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
                // 新规约: extends/module/Weline_Sticker/{模块名}/{文件路径}
                // 例如: Weline/Demo/view/templates/Backend/index.phtml
                $targetInfo = $this->parseTargetFromPath($relativePath);

                if ($targetInfo) {
                    $stickers[] = [
                        'source_module' => $sourceModule,
                        'source_base_path' => $basePath,
                        'sticker_file' => $stickerFilePath,
                        'sticker_relative_path' => $relativePath,
                        'target_module' => $targetInfo['target_module'],
                        'target_file' => $targetInfo['target_file'],
                        'type' => $type
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("扫描模块 Sticker 失败: {$sourceModule}, 错误: " . $e->getMessage());
        }

        return $stickers;
    }

    /**
     * 扫描主题 Sticker 目录
     *
     * @param string $sourceModule 来源模块名
     * @param string $basePath 模块基础路径
     * @param string $stickerThemeDir 主题 Sticker 根目录 (extends/theme)
     * @return array
     */
    private function scanThemeStickers(string $sourceModule, string $basePath, string $stickerThemeDir): array
    {
        $stickers = [];

        try {
            // 遍历所有主题目录
            $themeDirs = glob($stickerThemeDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            foreach ($themeDirs as $themeDir) {
                $themeName = basename($themeDir);
                $welineStickerDir = $themeDir . DIRECTORY_SEPARATOR . 'Weline_Sticker';

                if (!is_dir($welineStickerDir)) {
                    continue;
                }

                // 扫描该主题下的 Weline_Sticker 目录
                $themeStickers = $this->scanModuleStickers($sourceModule, $basePath, $welineStickerDir, 'theme');
                foreach ($themeStickers as &$sticker) {
                    $sticker['theme_name'] = $themeName;
                }
                $stickers = array_merge($stickers, $themeStickers);
            }
        } catch (\Exception $e) {
            error_log("扫描主题 Sticker 失败: {$sourceModule}, 错误: " . $e->getMessage());
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

        // 检查模块 Sticker 目录
        $stickerModuleDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_MODULE_DIR;
        if (is_dir($stickerModuleDir)) {
            return true;
        }

        // 检查主题 Sticker 目录
        $stickerThemeDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::STICKER_THEME_DIR;
        if (is_dir($stickerThemeDir)) {
            $themeDirs = glob($stickerThemeDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            foreach ($themeDirs as $themeDir) {
                $welineStickerDir = $themeDir . DIRECTORY_SEPARATOR . 'Weline_Sticker';
                if (is_dir($welineStickerDir)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 从模块 base_path 提取模块路径名
     * 例如: app/code/Weline/Sticker -> Weline/Sticker
     *
     * @param string $basePath 模块基础路径
     * @return string|null
     */
    private function extractModulePathFromBasePath(string $basePath): ?string
    {
        // 标准化路径
        $basePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, rtrim($basePath, '/\\'));

        // 查找 app/code 或 vendor 目录
        $appCodePos = strpos($basePath, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR);
        $vendorPos = strpos($basePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

        if ($appCodePos !== false) {
            // app/code/Weline/Sticker -> Weline/Sticker
            $relativePath = substr($basePath, $appCodePos + strlen(DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR));
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        } elseif ($vendorPos !== false) {
            // vendor/Weline/Sticker -> Weline/Sticker
            $relativePath = substr($basePath, $vendorPos + strlen(DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR));
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }

        return null;
    }
}

