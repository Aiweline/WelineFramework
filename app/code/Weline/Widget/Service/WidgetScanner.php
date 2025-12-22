<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Service;

use Weline\Framework\App\Env;
use Weline\Widget\Model\Widget\LocalDescription;

/**
 * 部件扫描服务
 * 扫描所有模块的 Widget 扩展
 */
class WidgetScanner
{
    /**
     * 允许的部件类型列表
     */
    private const ALLOWED_TYPES = [
        'header', 'footer', 'sidebar', 'content', 'banner',
        'carousel', 'card', 'form', 'list', 'grid', 'navigation',
        'breadcrumb', 'pagination', 'modal', 'tabs', 'accordion',
        'slider', 'gallery', 'testimonial', 'pricing', 'team',
        'blog', 'product', 'category', 'search', 'filter', 'map',
        'video', 'audio', 'social', 'newsletter', 'faq', 'timeline',
        'stats', 'counter', 'progress', 'chart', 'table', 'calendar',
        'chat', 'comment'
    ];

    /**
     * 扫描所有模块的部件
     *
     * @return array 返回格式：
     * [
     *   'header' => [
     *     'default' => [
     *       'name' => '默认头部',
     *       'description' => '...',
     *       'type' => 'header',
     *       'module' => 'YourModule',
     *       'path' => '...',
     *       'config' => [...]
     *     ]
     *   ],
     *   'footer' => [...]
     * ]
     */
    public function scanAllWidgets(): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            // 扫描模块的 Widget 扩展
            $widgets = $this->scanModuleWidgets($moduleName, $basePath);
            if (!empty($widgets)) {
                foreach ($widgets as $widget) {
                    $type = $widget['type'] ?? '';
                    $name = $widget['name'] ?? '';
                    
                    if (empty($type) || empty($name)) {
                        continue;
                    }

                    if (!isset($result[$type])) {
                        $result[$type] = [];
                    }

                    $result[$type][$name] = $widget;
                }
            }
        }

        return $result;
    }

    /**
     * 扫描指定模块的部件
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array
     */
    private function scanModuleWidgets(string $moduleName, string $basePath): array
    {
        $result = [];
        
        // 扫描 extends/Weline_Widget/Weline_Widget/ 目录
        $widgetDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
                     'Weline_Widget' . DIRECTORY_SEPARATOR . 'Weline_Widget';
        
        if (!is_dir($widgetDir)) {
            return $result;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($widgetDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isDir()) {
                    continue;
                }

                $dirPath = $file->getPathname();
                
                // 检查是否是部件目录（包含 widget.php）
                $widgetFile = $dirPath . DIRECTORY_SEPARATOR . 'widget.php';
                if (!file_exists($widgetFile)) {
                    continue;
                }

                // 解析部件类型和名称
                // 路径格式：.../Weline_Widget/Weline_Widget/{type}/{name}/
                $relativePath = str_replace($widgetDir . DIRECTORY_SEPARATOR, '', $dirPath);
                $relativePath = str_replace('\\', '/', $relativePath);
                $pathParts = explode('/', $relativePath);

                if (count($pathParts) < 2) {
                    continue;
                }

                $type = $pathParts[0] ?? '';
                $name = $pathParts[1] ?? '';

                if (empty($type) || empty($name)) {
                    continue;
                }

                // 验证部件类型
                if (!in_array($type, self::ALLOWED_TYPES, true)) {
                    continue;
                }

                // 读取 widget.php 配置
                $config = $this->readWidgetConfig($widgetFile, $moduleName, $type, $name, $dirPath);
                if ($config) {
                    $result[] = $config;
                }
            }
        } catch (\Exception $e) {
            error_log("扫描模块 {$moduleName} 的部件时出错: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * 读取部件配置文件
     *
     * @param string $widgetFile widget.php 文件路径
     * @param string $moduleName 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string $widgetPath 部件目录路径
     * @return array|null
     */
    private function readWidgetConfig(
        string $widgetFile,
        string $moduleName,
        string $type,
        string $name,
        string $widgetPath
    ): ?array {
        try {
            $config = include $widgetFile;
            if (!is_array($config)) {
                return null;
            }

            // 验证必需字段
            if (empty($config['name']) || empty($config['type'])) {
                return null;
            }

            // 确保类型匹配
            if ($config['type'] !== $type) {
                return null;
            }

            // 检查模板文件或 Block 类
            $hasTemplate = false;
            $templatePath = $config['template'] ?? '';
            $blockClass = $config['block_class'] ?? '';

            if (!empty($blockClass)) {
                $hasTemplate = true;
            } elseif (!empty($templatePath)) {
                $hasTemplate = true;
            } else {
                // 检查默认模板文件
                $defaultTemplate = $widgetPath . DIRECTORY_SEPARATOR . 'template.phtml';
                if (file_exists($defaultTemplate)) {
                    $hasTemplate = true;
                    // 自动生成模板路径
                    $config['template'] = $moduleName . '::widgets/' . $type . '/' . $name . '.phtml';
                }
            }

            if (!$hasTemplate) {
                error_log("警告: 部件 {$moduleName}/{$type}/{$name} 缺少模板文件或 Block 类");
                return null;
            }

            // 获取翻译的名称和描述
            $translatedName = LocalDescription::getTranslatedName(
                $moduleName,
                $type,
                $name,
                null,
                $config['name']
            );
            $translatedDescription = LocalDescription::getTranslatedDescription(
                $moduleName,
                $type,
                $name,
                null,
                $config['description'] ?? ''
            );
            
            // 构建部件信息
            return [
                'name' => $translatedName ?: $config['name'],
                'description' => $translatedDescription ?: ($config['description'] ?? ''),
                'original_name' => $config['name'], // 保留原始名称
                'original_description' => $config['description'] ?? '', // 保留原始描述
                'type' => $type,
                'widget_name' => $name,
                'module' => $moduleName,
                'version' => $config['version'] ?? '1.0.0',
                'author' => $config['author'] ?? '',
                'path' => $widgetPath,
                'widget_file' => $widgetFile,
                'template' => $config['template'] ?? '',
                'block_class' => $blockClass,
                'params' => $config['params'] ?? [],
                'dependencies' => $config['dependencies'] ?? [],
                'doc_file' => $this->findDocFile($widgetPath),
                'config' => $config
            ];
        } catch (\Exception $e) {
            error_log("读取部件配置文件 {$widgetFile} 时出错: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 查找文档文件
     *
     * @param string $widgetPath 部件目录路径
     * @return string|null
     */
    private function findDocFile(string $widgetPath): ?string
    {
        $docFile = $widgetPath . DIRECTORY_SEPARATOR . 'doc.md';
        if (file_exists($docFile)) {
            return $docFile;
        }
        return null;
    }

    /**
     * 扫描指定部件
     *
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @return array|null
     */
    public function scanWidget(string $type, string $name): ?array
    {
        $allWidgets = $this->scanAllWidgets();
        return $allWidgets[$type][$name] ?? null;
    }

    /**
     * 获取允许的部件类型列表
     *
     * @return array
     */
    public function getAllowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /**
     * 验证部件类型是否有效
     *
     * @param string $type 部件类型
     * @return bool
     */
    public function isValidType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TYPES, true);
    }
}

