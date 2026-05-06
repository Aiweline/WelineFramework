<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Env;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Scan;

/**
 * 事件扫描服务
 * 扫描所有模块的 event.php 规约文件和对应的文档文件
 */
class EventScanner
{
    private ModuleScanService $moduleScanService;

    public function __construct($moduleScanService = null)
    {
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService(new Scan());
    }

    /**
     * 扫描所有模块的事件规约信息
     *
     * @return array 返回格式：
     * [
     *   'Weline_Admin' => [
     *     'Weline_Admin::msg' => [
     *       'name' => '系统消息通知',
     *       'description' => '...',
     *       'doc' => '系统消息通知.md',
     *       'doc_path' => 'doc/event/系统消息通知.md',
     *       'has_spec' => true,
     *       'has_doc' => true
     *     ]
     *   ]
     * ]
     */
    public function scanAllEvents(): array
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
            RegistryProgress::module('Event scan module', $moduleIndex, $totalModules, (string)$moduleName, 'start');

            // 扫描模块的 event.php 规约文件
            $eventsConfig = $this->scanModuleEventConfig($moduleName, $basePath);
            RegistryProgress::module(
                'Event scan module',
                $moduleIndex,
                $totalModules,
                (string)$moduleName,
                sprintf('done event.php=%s events=%d', !empty($eventsConfig) ? 'yes' : 'no', count($eventsConfig ?? []))
            );
            if (!empty($eventsConfig)) {
                $result[$moduleName] = $eventsConfig;
            }
        }

        return $result;
    }

    /**
     * 扫描模块的 event.php 规约文件
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function scanModuleEventConfig(string $moduleName, string $basePath): ?array
    {
        $eventFile = $this->moduleScanService->resolveFile($basePath, 'event.php');
        if ($eventFile === null) {
            return null;
        }

        $config = include $eventFile;
        if (!is_array($config)) {
            return null;
        }

        $result = [];
        
        // 验证每个事件的文档是否存在
        foreach ($config as $eventName => $eventConfig) {
            if (!is_array($eventConfig)) {
                continue;
            }

            // 验证事件名是否符合规范：必须以模块名开头
            // 格式：模块名::事件名 或 模块名_子模块::事件名
            // validateEventName 方法会直接致命错误退出，如果不符合规范
            $this->validateEventName($eventName, $moduleName, $basePath);

            $docFileName = $eventConfig['doc'] ?? '';
            $hasDoc = false;
            $docPath = '';

            if (!empty($docFileName)) {
                // 检查 doc/event/ 目录下的文档文件
                $docFile = $this->moduleScanService->resolveFile($basePath, 'doc/event/' . $docFileName);
                if ($docFile !== null) {
                    $hasDoc = true;
                    $docPath = 'doc/event/' . $docFileName;
                }
            }

            $result[$eventName] = [
                'name' => $eventConfig['name'] ?? $eventName,
                'description' => $eventConfig['description'] ?? '',
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
     * 扫描指定模块的事件规约信息
     *
     * @param array $moduleNames 模块名列表
     * @return array 返回格式与 scanAllEvents 相同
     */
    public function scanModules(array $moduleNames): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();
        $totalModules = count($moduleNames);
        $moduleIndex = 0;

        foreach ($moduleNames as $moduleName) {
            $moduleIndex++;
            if (!isset($modules[$moduleName])) {
                RegistryProgress::module('Event scan module', $moduleIndex, $totalModules, (string)$moduleName, 'skip missing');
                continue;
            }
            
            $module = $modules[$moduleName];
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                RegistryProgress::module('Event scan module', $moduleIndex, $totalModules, (string)$moduleName, 'skip inactive');
                continue;
            }

            RegistryProgress::module('Event scan module', $moduleIndex, $totalModules, (string)$moduleName, 'start');
            $eventsConfig = $this->scanModuleEventConfig($moduleName, $basePath);
            RegistryProgress::module(
                'Event scan module',
                $moduleIndex,
                $totalModules,
                (string)$moduleName,
                sprintf('done event.php=%s events=%d', !empty($eventsConfig) ? 'yes' : 'no', count($eventsConfig ?? []))
            );
            if (!empty($eventsConfig)) {
                $result[$moduleName] = $eventsConfig;
            }
        }

        return $result;
    }

    /**
     * 验证事件名是否符合规范
     * 事件名必须以模块名开头，格式：模块名::事件名
     *
     * @param string $eventName 事件名
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径（用于错误提示）
     * @return bool
     */
    private function validateEventName(string $eventName, string $moduleName, string $basePath): bool
    {
        // 动态事件模式（包含 {}）跳过验证
        if (str_contains($eventName, '{') && str_contains($eventName, '}')) {
            return true;
        }

        // 检查事件名是否包含 ::
        if (!str_contains($eventName, '::')) {
            $errorMessage = sprintf(
                "\n\n[致命错误] 事件名不符合命名规范\n" .
                "事件名：%s\n" .
                "问题：事件名必须包含 \"::\" 分隔符\n" .
                "正确格式：模块名::事件名\n" .
                "文件位置：%s/event.php\n\n",
                $eventName,
                $basePath
            );
            fwrite(STDERR, $errorMessage);
            exit(1);
        }

        // 提取事件名前缀（:: 之前的部分）
        $prefix = explode('::', $eventName)[0];

        // 特殊处理：Framework_ 开头的事件视为 Weline_Framework 模块的事件
        if ($moduleName === 'Weline_Framework' && str_starts_with($prefix, 'Framework_')) {
            return true;
        }

        // 特殊处理：App:: 开头的事件视为 Weline_Framework 模块的事件
        if ($moduleName === 'Weline_Framework' && str_starts_with($prefix, 'App')) {
            return true;
        }

        // 检查前缀是否以模块名开头
        // 支持：模块名 或 模块名_子模块 格式
        if (!str_starts_with($prefix, $moduleName)) {
            $errorMessage = sprintf(
                "\n\n[致命错误] 事件名不符合命名规范\n" .
                "事件名：%s\n" .
                "事件名前缀：%s\n" .
                "模块名：%s\n" .
                "问题：事件名前缀必须以模块名开头\n" .
                "正确格式：%s::事件名 或 %s_子模块::事件名\n" .
                "文件位置：%s/event.php\n\n",
                $eventName,
                $prefix,
                $moduleName,
                $moduleName,
                $moduleName,
                $basePath
            );
            fwrite(STDERR, $errorMessage);
            exit(1);
        }

        return true;
    }
}

