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

/**
 * 事件扫描服务
 * 扫描所有模块的 event.php 规约文件和对应的文档文件
 */
class EventScanner
{
    /**
     * 扫描所有模块的事件规约信息
     *
     * @return array 返回格式：
     * [
     *   'Weline_Admin' => [
     *     'Weline_Framework::msg' => [
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

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            // 扫描模块的 event.php 规约文件
            $eventsConfig = $this->scanModuleEventConfig($moduleName, $basePath);
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
        $eventFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'event.php';
        if (!file_exists($eventFile)) {
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
            if (!$this->validateEventName($eventName, $moduleName)) {
                $errorMessage = sprintf(
                    '事件名 "%s" 不符合命名规范。事件名必须以模块名 "%s" 开头，格式：%s::事件名。文件：%s/event.php',
                    $eventName,
                    $moduleName,
                    $moduleName,
                    $basePath
                );
                throw new \RuntimeException($errorMessage);
            }

            $docFileName = $eventConfig['doc'] ?? '';
            $hasDoc = false;
            $docPath = '';

            if (!empty($docFileName)) {
                // 检查 doc/event/ 目录下的文档文件
                $docFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'event' . DIRECTORY_SEPARATOR . $docFileName;
                if (file_exists($docFile)) {
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
     * 验证事件名是否符合规范
     * 事件名必须以模块名开头，格式：模块名::事件名
     *
     * @param string $eventName 事件名
     * @param string $moduleName 模块名
     * @return bool
     */
    private function validateEventName(string $eventName, string $moduleName): bool
    {
        // 动态事件模式（包含 {}）跳过验证
        if (str_contains($eventName, '{') && str_contains($eventName, '}')) {
            return true;
        }

        // 检查事件名是否包含 ::
        if (!str_contains($eventName, '::')) {
            return false;
        }

        // 提取事件名前缀（:: 之前的部分）
        $prefix = explode('::', $eventName)[0];

        // 检查前缀是否以模块名开头
        // 支持：模块名 或 模块名_子模块 格式
        return str_starts_with($prefix, $moduleName);
    }
}

