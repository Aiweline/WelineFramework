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
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);

                // 解析目标模块
                // extends/module/Weline_Ai/Adapter/... 或 extends/theme/{ThemeName}/Weline_Sticker/...
                $pathParts = explode('/', $relativePath);
                if (count($pathParts) < 3) {
                    continue;
                }

                // 跳过 extends/module 或 extends/theme
                $targetModulePath = $pathParts[2] ?? '';
                if (empty($targetModulePath)) {
                    continue;
                }

                // 将路径转换为模块名 (Weline/Ai -> Weline_Ai)
                $targetModule = str_replace('/', '_', $targetModulePath);

                if (!isset($result[$targetModule])) {
                    $result[$targetModule] = [];
                }

                // 提取文件相对路径（去掉 extends/{type}/{ModuleName}/ 前缀）
                $fileRelativePath = implode('/', array_slice($pathParts, 3));

                $result[$targetModule][] = [
                    'type' => $type,
                    'source_module' => $sourceModule,
                    'source_file' => $filePath,
                    'target_module' => $targetModule,
                    'file_path' => $fileRelativePath,
                    'relative_path' => $relativePath
                ];
            }
        } catch (\Exception $e) {
            error_log("扫描模块扩展失败: {$sourceModule}, 错误: " . $e->getMessage());
        }
    }
}

