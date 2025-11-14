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
}

