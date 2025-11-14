<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends;

use Weline\Framework\App\Env;

/**
 * 扩展扫描服务
 * 扫描所有模块的 extends.php 规约文件和实际的扩展文件
 */
class ExtendsScanner
{
    /**
     * 扫描所有模块的扩展信息
     *
     * @return array 返回格式：
     * [
     *   'Weline_Ai' => [
     *     'extends' => [...],
     *     'extended_by' => [...]
     *   ]
     * ]
     */
    public function scanAllExtends(): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            // 扫描模块的 extends.php 规约文件
            $extendsConfig = $this->scanModuleExtendsConfig($moduleName, $basePath);
            if ($extendsConfig) {
                $result[$moduleName] = [
                    'extends' => $extendsConfig,
                    'extended_by' => []
                ];
            }

            // 扫描模块对其他模块的扩展
            $extendedBy = $this->scanModuleExtends($moduleName, $basePath);
            if (!empty($extendedBy)) {
                foreach ($extendedBy as $targetModule => $extendInfo) {
                    if (!isset($result[$targetModule])) {
                        $result[$targetModule] = [
                            'extends' => [],
                            'extended_by' => []
                        ];
                    }
                    if (!isset($result[$targetModule]['extended_by'][$moduleName])) {
                        $result[$targetModule]['extended_by'][$moduleName] = [];
                    }
                    $result[$targetModule]['extended_by'][$moduleName] = array_merge(
                        $result[$targetModule]['extended_by'][$moduleName] ?? [],
                        $extendInfo
                    );
                }
            }
        }

        return $result;
    }

    /**
     * 扫描模块的 extends.php 规约文件
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function scanModuleExtendsConfig(string $moduleName, string $basePath): ?array
    {
        $extendsFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends.php';
        if (!file_exists($extendsFile)) {
            return null;
        }

        $config = include $extendsFile;
        if (!is_array($config)) {
            return null;
        }

        // 验证 extends.md 文档是否存在
        $docFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends.md';
        if (!file_exists($docFile)) {
            // 记录警告，但不阻止处理
            error_log("警告: 模块 {$moduleName} 定义了 extends.php 但缺少 extends.md 文档");
        }

        return $config;
    }

    /**
     * 扫描模块对其他模块的扩展
     *
     * @param string $sourceModule 来源模块名
     * @param string $basePath 模块基础路径
     * @return array
     */
    private function scanModuleExtends(string $sourceModule, string $basePath): array
    {
        $result = [];
        
        // 扫描 extends/module/{ModuleName}/ 目录
        $extendsModuleDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'module';
        if (is_dir($extendsModuleDir)) {
            $this->scanExtendsDirectory($extendsModuleDir, $sourceModule, $basePath, 'module', $result);
        }

        // 扫描 extends/theme/{ThemeName}/ 目录
        $extendsThemeDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'theme';
        if (is_dir($extendsThemeDir)) {
            $this->scanExtendsDirectory($extendsThemeDir, $sourceModule, $basePath, 'theme', $result);
        }

        return $result;
    }

    /**
     * 扫描扩展目录
     *
     * @param string $dir 目录路径
     * @param string $sourceModule 来源模块名
     * @param string $basePath 模块基础路径
     * @param string $type 类型 (module/theme)
     * @param array &$result 结果数组
     */
    private function scanExtendsDirectory(string $dir, string $sourceModule, string $basePath, string $type, array &$result): void
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();
                
                // 计算相对于模块根目录的相对路径
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                // 确保路径以 extends/ 开头
                if (!str_starts_with($relativePath, 'extends/')) {
                    continue;
                }

                // 解析目标模块
                // extends/module/Weline_Ai/Adapter/... 或 extends/module/Weline_Sticker/... 或 extends/theme/{ThemeName}/Weline_Sticker/...
                $pathParts = explode('/', $relativePath);
                if (count($pathParts) < 3) {
                    continue;
                }

                // 跳过 extends/module 或 extends/theme
                $targetModulePath = $pathParts[2] ?? '';
                if (empty($targetModulePath)) {
                    continue;
                }

                // 将路径转换为模块名 (Weline/Ai -> Weline_Ai, Weline_Sticker -> Weline_Sticker)
                $targetModule = str_replace('/', '_', $targetModulePath);
                
                // 验证模块名格式（必须是 Vendor_Module 格式，且不包含特殊字符）
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $targetModule)) {
                    continue; // 跳过无效的模块名
                }

                // 特殊处理：如果是 Weline_Sticker 扩展，则跳过后续的路径解析
                // 因为 Sticker 扩展的目标是 Weline_Sticker 扩展点本身
                if ($targetModule === 'Weline_Sticker') {
                    // 对于 Sticker 扩展，我们需要找到真正的目标模块
                    // extends/module/Weline_Sticker/Weline/Demo/view/templates/... 
                    // 目标应该是 Weline_Demo，而不是 Weline_Sticker
                    if (count($pathParts) >= 5) {
                        // 需要至少 2 个路径段来组成模块名 (Vendor/Module)
                        $vendor = $pathParts[3] ?? '';
                        $module = $pathParts[4] ?? '';
                        if (!empty($vendor) && !empty($module)) {
                            $targetModule = $vendor . '_' . $module;
                            // 验证目标模块名格式
                            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $targetModule)) {
                                continue; // 跳过无效的模块名
                            }
                            // 提取文件相对路径（去掉 extends/{type}/Weline_Sticker/{Vendor}/{Module}/ 前缀）
                            $fileRelativePath = implode('/', array_slice($pathParts, 5));
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }
                } else {
                    // 提取文件相对路径（去掉 extends/{type}/{ModuleName}/ 前缀）
                    $fileRelativePath = implode('/', array_slice($pathParts, 3));
                }

                if (!isset($result[$targetModule])) {
                    $result[$targetModule] = [];
                }

                // 为 Sticker 扩展添加特殊标记
                $extendInfo = [
                    'type' => $type,
                    'source_module' => $sourceModule,
                    'source_file' => $filePath,
                    'target_module' => $targetModule,
                    'file_path' => $fileRelativePath,
                    'relative_path' => $relativePath
                ];

                // 如果是 Sticker 扩展，添加特殊信息
                if ($pathParts[2] ?? '' === 'Weline_Sticker') {
                    $extendInfo['is_sticker_extension'] = true;
                    $extendInfo['sticker_type'] = $type; // module 或 theme
                }

                $result[$targetModule][] = $extendInfo;
            }
        } catch (\Exception $e) {
            error_log("扫描模块扩展失败: {$sourceModule}, 错误: " . $e->getMessage());
        }
    }
}

