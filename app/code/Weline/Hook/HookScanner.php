<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;

/**
 * Hook 扫描服务
 * 扫描所有模块的 hook.php 规约文件（位于模块根目录）
 */
class HookScanner
{
    /**
     * 扫描所有模块的 Hook 规约信息
     *
     * @return array 返回格式：
     * [
     *   'Weline_Theme' => [
     *     'Weline_Theme::frontend::partials::footer::before' => [
     *       'name' => '页脚之前',
     *       'description' => '...',
     *       'doc' => 'hook/frontend/partials/footer/before.md',
     *       'doc_path' => 'doc/hook/frontend/partials/footer/before.md',
     *       'has_spec' => true,
     *       'has_doc' => true
     *     ]
     *   ]
     * ]
     */
    public function scanAllHooks(): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            // 扫描模块的 hook.php 规约文件（位于模块根目录）
            $hooksConfig = $this->scanModuleHookConfig($moduleName, $basePath);
            if (!empty($hooksConfig)) {
                $result[$moduleName] = $hooksConfig;
            }
        }

        return $result;
    }

    /**
     * 扫描模块的 hook.php 规约文件
     * 路径：{ModulePath}/hook.php（模块根目录）
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function scanModuleHookConfig(string $moduleName, string $basePath): ?array
    {
        // 扫描模块根目录下的 hook.php 文件
        $hookFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'hook.php';
        
        if (!file_exists($hookFile)) {
            return null;
        }

        $config = include $hookFile;
        if (!is_array($config)) {
            return null;
        }

        $result = [];
        
            // 验证每个 Hook 的文档是否存在
            foreach ($config as $hookName => $hookConfig) {
                if (!is_array($hookConfig)) {
                    continue;
                }

                // 验证 Hook 名是否符合规范
                // 简单格式的 hook（不包含 ::）用于向后兼容，跳过严格验证
                if (str_contains($hookName, '::')) {
                    $this->validateHookName($hookName, $moduleName, $basePath);
                }

            $docFileName = $hookConfig['doc'] ?? '';
            $hasDoc = false;
            $docPath = '';

            if (!empty($docFileName)) {
                // 检查 doc/hook/ 目录下的文档文件
                $docFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'hook' . DIRECTORY_SEPARATOR . $docFileName;
                if (file_exists($docFile)) {
                    $hasDoc = true;
                    $docPath = 'doc/hook/' . $docFileName;
                } else {
                    // 配置了doc但文档不存在，记录错误（不致命错误，只检测）
                    $errorMessage = sprintf(
                        "[警告] Hook 文档文件不存在\n" .
                        "模块：%s\n" .
                        "Hook 名：%s\n" .
                        "配置的文档路径：%s\n" .
                        "期望的文档文件：%s\n" .
                        "配置文件：%s/hook.php\n",
                        $moduleName,
                        $hookName,
                        $docFileName,
                        $docFile,
                        $basePath
                    );
                    error_log($errorMessage);
                }
            }

            $result[$hookName] = [
                'name' => $hookConfig['name'] ?? $hookName,
                'description' => $hookConfig['description'] ?? '',
                'doc' => $docFileName,
                'doc_path' => $docPath,
                'has_spec' => true,
                'has_doc' => $hasDoc,
                'module' => $moduleName
            ];
        }

        return !empty($result) ? $result : null;
    }

    /**
     * 验证 Hook 名是否符合规范
     * Hook 名必须以模块名开头，格式：模块名::area::type::component::position
     *
     * @param string $hookName Hook 名
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径（用于错误提示）
     * @return bool
     */
    private function validateHookName(string $hookName, string $moduleName, string $basePath): bool
    {
        // 检查 Hook 名是否包含 ::
        if (!str_contains($hookName, '::')) {
            $errorMessage = sprintf(
                "\n\n[致命错误] Hook 名不符合命名规范\n" .
                "Hook 名：%s\n" .
                "问题：Hook 名必须包含 \"::\" 分隔符\n" .
                "正确格式：模块名::area::type::component::position\n" .
                "文件位置：%s/hook.php\n\n",
                $hookName,
                $basePath
            );
            fwrite(STDERR, $errorMessage);
            exit(1);
        }

        // 提取 Hook 名前缀（第一个 :: 之前的部分）
        $parts = explode('::', $hookName, 2);
        $prefix = $parts[0] ?? '';

        // 检查前缀是否以模块名开头
        if (!str_starts_with($prefix, $moduleName)) {
            $errorMessage = sprintf(
                "\n\n[致命错误] Hook 名不符合命名规范\n" .
                "Hook 名：%s\n" .
                "Hook 名前缀：%s\n" .
                "模块名：%s\n" .
                "问题：Hook 名前缀必须以模块名开头\n" .
                "正确格式：%s::area::type::component::position\n" .
                "文件位置：%s/hook.php\n\n",
                $hookName,
                $prefix,
                $moduleName,
                $moduleName,
                $basePath
            );
            fwrite(STDERR, $errorMessage);
            exit(1);
        }

        // 验证 Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
        $pattern = '/^[A-Z][a-zA-Z0-9_]+::[a-z]+::[a-z]+::[a-z-]+::[a-z-]+$/';
        if (!preg_match($pattern, $hookName)) {
            $errorMessage = sprintf(
                "\n\n[致命错误] Hook 名不符合命名规范\n" .
                "Hook 名：%s\n" .
                "问题：Hook 名格式不正确\n" .
                "正确格式：{ModuleName}::{area}::{type}::{component}::{position}\n" .
                "  - area: frontend 或 backend\n" .
                "  - type: partials 或 layouts\n" .
                "  - component: 组件名（小写字母和连字符）\n" .
                "  - position: 位置名（小写字母和连字符）\n" .
                "文件位置：%s/hook.php\n\n",
                $hookName,
                $basePath,
                $moduleName
            );
            fwrite(STDERR, $errorMessage);
            exit(1);
        }

        return true;
    }
}
