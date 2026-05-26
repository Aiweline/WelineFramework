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
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Scan;

/**
 * Hook 扫描服务
 * 扫描所有模块的 hook.php 规约文件（位于模块根目录）
 */
class HookScanner
{
    private ModuleScanService $moduleScanService;

    public function __construct(
        $moduleScanService = null
    ) {
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService(new Scan());
    }

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
        $totalModules = 0;
        foreach ($modules as $module) {
            $basePath = $module['base_path'] ?? '';
            if (!empty($basePath) && ($module['status'] ?? false)) {
                $totalModules++;
            }
        }
        $moduleIndex = 0;

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }
            $moduleIndex++;
            RegistryProgress::module('Hook spec scan module', $moduleIndex, $totalModules, (string)$moduleName, 'start');

            // 扫描模块的 hook.php 规约文件（位于模块根目录）
            $hooksConfig = $this->scanModuleHookConfig($moduleName, $basePath);
            RegistryProgress::module(
                'Hook spec scan module',
                $moduleIndex,
                $totalModules,
                (string)$moduleName,
                sprintf('done hook.php=%s hooks=%d', !empty($hooksConfig) ? 'yes' : 'no', count($hooksConfig ?? []))
            );
            if (!empty($hooksConfig)) {
                $result[$moduleName] = $hooksConfig;
            }
            unset($hooksConfig);
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
        $hookFile = $this->moduleScanService->resolveFile($basePath, 'hook.php');
        
        if ($hookFile === null) {
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
                // 将文档路径中的斜杠统一为系统目录分隔符，确保跨平台兼容
                $docRelativePath = 'doc/hook/' . $docFileName;
                $docFile = $this->moduleScanService->resolveFile($basePath, $docRelativePath);
                if ($docFile !== null) {
                    $hasDoc = true;
                    $docPath = 'doc/hook/' . $docFileName;
                } else {
                    $expectedDocFile = $this->moduleScanService->buildPath($basePath, $docRelativePath);
                    // 配置了doc但文档不存在，抛出异常（在收集阶段就检测）
                    $errorMessage = sprintf(
                        "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                        "【致命错误】Hook 文档文件不存在\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                        "❌ Hook 文档文件缺失检测（收集阶段）\n\n" .
                        "模块：%s\n" .
                        "Hook 名：%s\n" .
                        "显示名称：%s\n" .
                        "配置的文档路径：%s\n" .
                        "期望的文档文件：%s\n" .
                        "配置文件：%s/hook.php\n\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                        "💡 解决方案：\n" .
                        "   请在模块的 doc/hook/ 目录下创建对应的文档文件。\n" .
                        "   文档文件路径：%s\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n",
                        $moduleName,
                        $hookName,
                        $hookConfig['name'] ?? $hookName,
                        $docFileName,
                        $expectedDocFile,
                        $basePath,
                        $expectedDocFile
                    );
                    throw new \RuntimeException($errorMessage);
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

        unset($config);
        return !empty($result) ? $result : null;
    }

    /**
     * 扫描指定模块的 Hook 规约信息
     *
     * @param array $moduleNames 模块名列表
     * @return array 返回格式与 scanAllHooks 相同
     */
    public function scanModulesHooks(array $moduleNames): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();
        $totalModules = count($moduleNames);
        $moduleIndex = 0;

        foreach ($moduleNames as $moduleName) {
            $moduleIndex++;
            if (!isset($modules[$moduleName])) {
                RegistryProgress::module('Hook spec scan module', $moduleIndex, $totalModules, (string)$moduleName, 'skip missing');
                continue;
            }
            
            $module = $modules[$moduleName];
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                RegistryProgress::module('Hook spec scan module', $moduleIndex, $totalModules, (string)$moduleName, 'skip inactive');
                continue;
            }

            RegistryProgress::module('Hook spec scan module', $moduleIndex, $totalModules, (string)$moduleName, 'start');
            $hooksConfig = $this->scanModuleHookConfig($moduleName, $basePath);
            RegistryProgress::module(
                'Hook spec scan module',
                $moduleIndex,
                $totalModules,
                (string)$moduleName,
                sprintf('done hook.php=%s hooks=%d', !empty($hooksConfig) ? 'yes' : 'no', count($hooksConfig ?? []))
            );
            if (!empty($hooksConfig)) {
                $result[$moduleName] = $hooksConfig;
            }
            unset($hooksConfig);
        }

        return $result;
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
                "\n\n【致命错误】Hook 名不符合命名规范\n" .
                "Hook 名：%s\n" .
                "问题：Hook 名必须包含 \"::\" 分隔符\n" .
                "正确格式：模块名::area::type::component::position\n" .
                "文件位置：%s/hook.php\n\n",
                $hookName,
                $basePath
            );
            throw new \RuntimeException($errorMessage);
        }

        // 提取 Hook 名前缀（第一个 :: 之前的部分）
        $parts = explode('::', $hookName, 2);
        $prefix = $parts[0] ?? '';

        // 检查前缀是否以模块名开头
        if (!str_starts_with($prefix, $moduleName)) {
            $errorMessage = sprintf(
                "\n\n【致命错误】Hook 名不符合命名规范\n" .
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
            throw new \RuntimeException($errorMessage);
        }

        // 验证 Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
        $pattern = '/^[A-Z][a-zA-Z0-9_]+::[a-z]+::[a-z]+::[a-z-]+::[a-z-]+$/';
        if (!preg_match($pattern, $hookName)) {
            $errorMessage = sprintf(
                "\n\n【致命错误】Hook 名不符合命名规范\n" .
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
            throw new \RuntimeException($errorMessage);
        }

        return true;
    }
}
