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
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Widget\Model\Widget\LocalDescription;

/**
 * 部件扫描服务
 * 扫描所有模块的 Widget 扩展
 * 
 * 支持两种定义方式：
 * 1. 简化方式（推荐）：widget.php 只包含模板路径，元数据从模板注释解析
 * 2. 完整方式（兼容）：widget.php 包含完整的部件配置数组
 */
class WidgetScanner
{
    private ?WidgetTemplateParser $templateParser = null;

    /**
     * 获取模板解析器
     */
    private function getTemplateParser(): WidgetTemplateParser
    {
        if ($this->templateParser === null) {
            $this->templateParser = ObjectManager::getInstance(WidgetTemplateParser::class);
        }
        return $this->templateParser;
    }
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
        'chat', 'comment', 'container'  // container 类型用于 header-container, footer-container 等容器部件
    ];

    /**
     * 扫描所有模块的部件
     * 
     * 注意：此方法仅用于收集阶段（CLI命令），运行时请使用 WidgetData::getAllWidgets()
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
        // 仅在 CLI 模式下执行扫描，Web 运行时应该使用 WidgetData
        if (PHP_SAPI !== 'cli') {
            w_log_warning("WidgetScanner::scanAllWidgets() 不应在 Web 运行时调用，请使用 WidgetData::getAllWidgets()", [], 'WidgetScanner');
            return [];
        }
        $result = [];
        
        // 从已收集的 extends 信息中提取部件配置
        // 获取所有扩展 Weline_Widget 的模块信息
        $extendedBy = ExtendsData::getExtendedBy('Weline_Widget');
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            // 获取源模块的基础路径
            $modules = Env::getInstance()->getModuleList();
            $module = $modules[$sourceModule] ?? null;
            if (!$module || !($module['status'] ?? false)) {
                continue;
            }
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }
            
            // 从扩展信息中查找 widget.php 文件
            foreach ($extensions as $extension) {
                $filePath = $extension['file_path'] ?? '';  // 相对路径，如：Weline_Theme/widget.php
                $sourceFile = $extension['source_file'] ?? '';  // 绝对路径
                
                // 检查是否是 widget.php 文件
                // 路径格式：extends/module/Weline_Widget/{ModuleName}/widget.php
                // filePath 格式：Weline_Theme/widget.php（相对路径）
                // sourceFile 格式：完整绝对路径
                $isWidgetFile = false;
                $widgetFile = '';
                
                // 检查相对路径
                if (!empty($filePath) && (str_ends_with($filePath, 'widget.php') || str_ends_with($filePath, '/widget.php'))) {
                    $isWidgetFile = true;
                    // 从相对路径构建完整路径
                    $widgetFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 
                                'extends' . DIRECTORY_SEPARATOR . 
                                'module' . DIRECTORY_SEPARATOR . 
                                'Weline_Widget' . DIRECTORY_SEPARATOR . 
                                str_replace('/', DIRECTORY_SEPARATOR, $filePath);
                }
                
                // 检查绝对路径
                if (!empty($sourceFile) && str_ends_with($sourceFile, 'widget.php')) {
                    $isWidgetFile = true;
                    $widgetFile = $sourceFile;
                }
                
                if (!$isWidgetFile || empty($widgetFile) || !file_exists($widgetFile)) {
                    continue;
                }
                    
                try {
                    $widgets = include $widgetFile;
                    if (is_array($widgets)) {
                        foreach ($widgets as $key => $widgetConfig) {
                            // 处理简化格式和完整格式
                            $config = $this->processWidgetEntry($widgetConfig, $sourceModule, $basePath, $key);
                            if ($config) {
                                $type = $config['type'] ?? '';
                                $name = $config['code'] ?? '';
                                
                                if (!empty($type) && !empty($name) && in_array($type, self::ALLOWED_TYPES, true)) {
                                    if (!isset($result[$type])) {
                                        $result[$type] = [];
                                    }
                                    $result[$type][$name] = $config;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    w_log_error("读取模块 {$sourceModule} 的集中部件配置文件时出错: " . $e->getMessage(), [], 'WidgetScanner');
                }
            }
        }
        
        // 如果扩展注册表中没有找到集中定义的部件，直接扫描文件系统（兼容性支持）
        // 扫描集中定义方式：extends/module/Weline_Widget/{ModuleName}/widget.php
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }
            
            // 扫描集中定义方式的 widget.php 文件
            $widgetFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 
                         'extends' . DIRECTORY_SEPARATOR . 
                         'module' . DIRECTORY_SEPARATOR . 
                         'Weline_Widget' . DIRECTORY_SEPARATOR . 
                         $moduleName . DIRECTORY_SEPARATOR . 
                         'widget.php';
            
            if (file_exists($widgetFile)) {
                try {
                    $widgets = include $widgetFile;
                    if (is_array($widgets)) {
                        foreach ($widgets as $key => $widgetConfig) {
                            // 处理简化格式和完整格式
                            $config = $this->processWidgetEntry($widgetConfig, $moduleName, $basePath, $key);
                            if ($config) {
                                $type = $config['type'] ?? '';
                                $name = $config['code'] ?? '';
                                
                                // 如果已存在同名部件，跳过
                                if (isset($result[$type][$name])) {
                                    continue;
                                }
                                
                                if (!empty($type) && !empty($name) && in_array($type, self::ALLOWED_TYPES, true)) {
                                    if (!isset($result[$type])) {
                                        $result[$type] = [];
                                    }
                                    $result[$type][$name] = $config;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    w_log_error("读取模块 {$moduleName} 的集中部件配置文件时出错: " . $e->getMessage(), [], 'WidgetScanner');
                }
            }
        }
        
        // 兼容旧的分散定义方式：extends/Weline_Widget/Weline_Widget/ 或 extends/Weline_Widget/{ModuleName}/
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !($module['status'] ?? false)) {
                continue;
            }

            // 扫描旧的分散定义方式
            $widgets = $this->scanLegacyWidgets($moduleName, $basePath);
            if (!empty($widgets)) {
                foreach ($widgets as $widget) {
                    $type = $widget['type'] ?? '';
                    $name = $widget['code'] ?? '';
                    
                    if (empty($type) || empty($name)) {
                        continue;
                    }

                    // 如果新方式已经存在同名部件，跳过旧方式的（新方式优先）
                    if (isset($result[$type][$name])) {
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
     * 扫描旧的分散定义方式的部件（兼容性支持）
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array
     */
    private function scanLegacyWidgets(string $moduleName, string $basePath): array
    {
        $result = [];
        
        // 兼容旧的分散定义方式：extends/Weline_Widget/Weline_Widget/ 或 extends/Weline_Widget/{ModuleName}/
        // 扫描两种路径格式（兼容性支持）：
        // 1. extends/Weline_Widget/Weline_Widget/ （Widget 模块自己的部件，保持兼容）
        // 2. extends/Weline_Widget/{ModuleName}/ （其他模块的部件，使用模块名）
        $widgetDirs = [
            rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
            'Weline_Widget' . DIRECTORY_SEPARATOR . 'Weline_Widget',
            rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
            'Weline_Widget' . DIRECTORY_SEPARATOR . $moduleName
        ];
        
        foreach ($widgetDirs as $widgetDir) {
            if (!is_dir($widgetDir)) {
                continue;
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
                    // 路径格式：.../Weline_Widget/{ModuleName或Weline_Widget}/{type}/{name}/
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
                    $config = $this->readWidgetConfig($widgetFile, $moduleName, $type, $name, $dirPath, $basePath);
                    if ($config) {
                        $result[] = $config;
                    }
                }
            } catch (\Exception $e) {
                w_log_error("扫描模块 {$moduleName} 的部件时出错: " . $e->getMessage(), [], 'WidgetScanner');
            }
        }

        return $result;
    }

    /**
     * 处理 widget.php 中的单个条目
     * 
     * 支持两种格式：
     * 1. 简化格式（字符串）：模板路径，从模板注释解析元数据
     *    'Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml'
     * 
     * 2. 简化格式（数组，只含 template）：从模板解析，可覆盖部分属性
     *    ['template' => 'Weline_Theme::...', 'exclusive' => true]
     * 
     * 3. 完整格式（数组）：传统的完整配置
     *    ['name' => '...', 'type' => '...', 'code' => '...', ...]
     * 
     * @param mixed $entry widget.php 中的条目
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @param mixed $key 数组键（用于调试）
     * @return array|null
     */
    private function processWidgetEntry($entry, string $moduleName, string $basePath, $key): ?array
    {
        try {
            // 格式1：纯字符串（模板路径）
            if (is_string($entry)) {
                return $this->processSimplifiedWidget($entry, [], $moduleName, $basePath);
            }
            
            // 格式2/3：数组
            if (is_array($entry)) {
                // 判断是简化格式还是完整格式
                // 完整格式必须包含 name, type, code
                $isFullFormat = !empty($entry['name']) && !empty($entry['type']) && !empty($entry['code']);
                
                if ($isFullFormat) {
                    // 完整格式 - 使用原有的处理方式
                    return $this->readWidgetConfigFromArray($entry, $moduleName, $basePath);
                }
                
                // 简化格式 - 必须包含 template
                if (!empty($entry['template'])) {
                    return $this->processSimplifiedWidget($entry['template'], $entry, $moduleName, $basePath);
                }
            }
            
            return null;
        } catch (\Exception $e) {
            w_log_error("处理部件条目失败 (key={$key}): " . $e->getMessage(), [], 'WidgetScanner');
            return null;
        }
    }

    /**
     * 处理简化格式的部件
     * 
     * @param string $templatePath 模板路径
     * @param array $overrides 覆盖的属性
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function processSimplifiedWidget(
        string $templatePath, 
        array $overrides, 
        string $moduleName, 
        string $basePath
    ): ?array {
        try {
            // 使用模板解析器解析模板注释
            $parser = $this->getTemplateParser();
            $templateConfig = $parser->parse($templatePath);
            
            if (empty($templateConfig)) {
                w_log_error("无法解析模板: {$templatePath}", [], 'WidgetScanner');
                return null;
            }
            
            // 合并：模板解析结果 + 覆盖属性
            $mergedConfig = array_merge($templateConfig, $overrides);
            
            // 确保 module 正确
            if (empty($mergedConfig['module'])) {
                $mergedConfig['module'] = $moduleName;
            }
            
            // 生成 name（如果未定义）
            if (empty($mergedConfig['name'])) {
                $mergedConfig['name'] = $parser->generateName($mergedConfig);
            }
            
            // 验证必需字段
            if (!$parser->validate($mergedConfig)) {
                w_log_error("部件配置验证失败: {$templatePath}", [], 'WidgetScanner');
                return null;
            }
            
            // 转换为标准的部件配置格式
            return $this->readWidgetConfigFromArray($mergedConfig, $moduleName, $basePath);
        } catch (\Exception $e) {
            w_log_error("处理简化部件失败: {$templatePath}, 错误: " . $e->getMessage(), [], 'WidgetScanner');
            return null;
        }
    }

    /**
     * 从数组读取部件配置（新的集中定义方式）
     *
     * @param array $widgetConfig 部件配置数组
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function readWidgetConfigFromArray(
        array $widgetConfig,
        string $moduleName,
        string $basePath
    ): ?array {
        try {
            $type = $widgetConfig['type'] ?? '';
            $name = $widgetConfig['code'] ?? '';
            
            if (empty($type) || empty($name)) {
                return null;
            }
            
            // 验证部件类型
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                return null;
            }
            
            // 检查模板文件或 Block 类
            $hasTemplate = false;
            $templatePath = $widgetConfig['template'] ?? '';
            $blockClass = $widgetConfig['block_class'] ?? '';
            
            if (!empty($blockClass)) {
                $hasTemplate = true;
            } elseif (!empty($templatePath)) {
                $hasTemplate = true;
            }
            
            if (!$hasTemplate) {
                w_log_warning("部件 {$moduleName}/{$type}/{$name} 缺少模板文件或 Block 类", [], 'WidgetScanner');
                return null;
            }
            
            // 获取翻译的名称和描述
            $translatedName = LocalDescription::getTranslatedName(
                $moduleName,
                $type,
                $name,
                null,
                $widgetConfig['name'] ?? ''
            );
            $translatedDescription = LocalDescription::getTranslatedDescription(
                $moduleName,
                $type,
                $name,
                null,
                $widgetConfig['description'] ?? ''
            );
            
            // 验证文档文件是否存在（强制检查，如果配置了doc但文档不存在，致命错误）
            $docFileName = $widgetConfig['doc'] ?? '';
            $hasDoc = false;
            $docPath = '';
            
            if (!empty($docFileName)) {
                // 检查 doc/widget/ 目录下的文档文件
                $docFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'widget' . DIRECTORY_SEPARATOR . $docFileName;
                if (file_exists($docFile)) {
                    $hasDoc = true;
                    $docPath = 'doc/widget/' . $docFileName;
                } else {
                    // 配置了 doc 但文档不存在
                    // CLI 下保留原来的严格行为，Web 请求下只记录错误并跳过该部件，避免整站中断
                    $errorMessage = sprintf(
                        "\n\n[部件文档缺失] 部件文档文件不存在\n" .
                        "模块：%s\n" .
                        "部件类型：%s\n" .
                        "部件代码：%s\n" .
                        "配置的文档路径：%s\n" .
                        "期望的文档文件：%s\n" .
                        "配置文件：%s\n\n",
                        $moduleName,
                        $type,
                        $name,
                        $docFileName,
                        $docFile,
                        rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
                        'module' . DIRECTORY_SEPARATOR . 'Weline_Widget' . DIRECTORY_SEPARATOR . 
                        $moduleName . DIRECTORY_SEPARATOR . 'widget.php'
                    );
                    
                    if (PHP_SAPI === 'cli') {
                        // 命令行场景：仍然通过 STDERR 明确提示，并中止命令，方便开发排查
                        fwrite(STDERR, $errorMessage);
                        exit(1);
                    }
                    
                    // Web 场景：仅记录错误日志，跳过该部件，避免导致整站无法访问
                    w_log_error($errorMessage, [], 'WidgetScanner');
                    return null;
                }
            }
            
            // 构建部件信息
            return [
                'name' => $translatedName ?: ($widgetConfig['name'] ?? ''),
                'description' => $translatedDescription ?: ($widgetConfig['description'] ?? ''),
                'original_name' => $widgetConfig['name'] ?? '',
                'original_description' => $widgetConfig['description'] ?? '',
                'type' => $type,
                'code' => $name,
                'area' => $widgetConfig['area'] ?? 'frontend', // 默认前端
                'module' => $moduleName,
                'version' => $widgetConfig['version'] ?? '1.0.0',
                'author' => $widgetConfig['author'] ?? '',
                'path' => dirname(rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
                         'module' . DIRECTORY_SEPARATOR . 'Weline_Widget' . DIRECTORY_SEPARATOR . $moduleName),
                'widget_file' => rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 
                               'module' . DIRECTORY_SEPARATOR . 'Weline_Widget' . DIRECTORY_SEPARATOR . 
                               $moduleName . DIRECTORY_SEPARATOR . 'widget.php',
                'template' => $templatePath,
                'block_class' => $blockClass,
                'params' => $widgetConfig['params'] ?? [],
                'dependencies' => $widgetConfig['dependencies'] ?? [],
                'doc' => $docFileName,
                'doc_path' => $docPath,
                'has_doc' => $hasDoc,
                'disabled' => !empty($widgetConfig['disabled']), // 保留禁用状态
                'config' => $widgetConfig
            ];
        } catch (\Exception $e) {
            w_log_error("读取部件配置数组时出错: " . $e->getMessage(), [], 'WidgetScanner');
            return null;
        }
    }

    /**
     * 读取部件配置文件（旧的分散定义方式）
     *
     * @param string $widgetFile widget.php 文件路径
     * @param string $moduleName 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string $widgetPath 部件目录路径
     * @param string $basePath 模块基础路径
     * @return array|null
     */
    private function readWidgetConfig(
        string $widgetFile,
        string $moduleName,
        string $type,
        string $name,
        string $widgetPath,
        string $basePath
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
                w_log_warning("部件 {$moduleName}/{$type}/{$name} 缺少模板文件或 Block 类", [], 'WidgetScanner');
                return null;
            }

            // 有模板路径且无 block_class 时，校验模板文件存在，避免注册表出现预览 404
            if (empty($blockClass) && !empty($config['template'])) {
                $template = ObjectManager::getInstance(Template::class);
                $realPath = $template->getTemplateRealPath($config['template']);
                if ($realPath === '' || !file_exists($realPath)) {
                    w_log_warning("部件 {$moduleName}/{$type}/{$name} 模板文件不存在，已跳过: {$config['template']}", [], 'WidgetScanner');
                    return null;
                }
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
            
            // 验证文档文件是否存在（强制检查，如果配置了doc但文档不存在，致命错误）
            $docFileName = $config['doc'] ?? '';
            $hasDoc = false;
            $docPath = '';
            
            if (!empty($docFileName) && !empty($basePath)) {
                // 检查 doc/widget/ 目录下的文档文件
                $docFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'widget' . DIRECTORY_SEPARATOR . $docFileName;
                if (file_exists($docFile)) {
                    $hasDoc = true;
                    $docPath = 'doc/widget/' . $docFileName;
                } else {
                    // 配置了 doc 但文档不存在
                    $errorMessage = sprintf(
                        "\n\n[部件文档缺失] 部件文档文件不存在\n" .
                        "模块：%s\n" .
                        "部件类型：%s\n" .
                        "部件名称：%s\n" .
                        "配置的文档路径：%s\n" .
                        "期望的文档文件：%s\n" .
                        "配置文件：%s\n\n",
                        $moduleName,
                        $type,
                        $name,
                        $docFileName,
                        $docFile,
                        $widgetFile
                    );
                    
                    if (PHP_SAPI === 'cli') {
                        // 命令行下严格失败，方便开发排查
                        fwrite(STDERR, $errorMessage);
                        exit(1);
                    }
                    
                    // Web 场景：只记录错误并跳过该部件
                    w_log_error($errorMessage, [], 'WidgetScanner');
                    return null;
                }
            }
            
            // 构建部件信息
            return [
                'name' => $translatedName ?: $config['name'],
                'description' => $translatedDescription ?: ($config['description'] ?? ''),
                'original_name' => $config['name'], // 保留原始名称
                'original_description' => $config['description'] ?? '', // 保留原始描述
                'type' => $type,
                'code' => $name,
                'area' => $config['area'] ?? 'frontend', // 默认前端
                'module' => $moduleName,
                'version' => $config['version'] ?? '1.0.0',
                'author' => $config['author'] ?? '',
                'path' => $widgetPath,
                'widget_file' => $widgetFile,
                'template' => $config['template'] ?? '',
                'block_class' => $blockClass,
                'params' => $config['params'] ?? [],
                'dependencies' => $config['dependencies'] ?? [],
                'doc' => $docFileName,
                'disabled' => !empty($config['disabled']), // 保留禁用状态
                'doc_path' => $docPath,
                'has_doc' => $hasDoc,
                'config' => $config
            ];
        } catch (\Exception $e) {
            w_log_error("读取部件配置文件 {$widgetFile} 时出错: " . $e->getMessage(), [], 'WidgetScanner');
            return null;
        }
    }


    /**
     * 扫描指定部件
     * 
     * 注意：此方法仅用于收集阶段（CLI命令），运行时请使用 WidgetData::getWidget()
     *
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @return array|null
     */
    public function scanWidget(string $type, string $name): ?array
    {
        // 仅在 CLI 模式下执行扫描，Web 运行时应该使用 WidgetData
        if (PHP_SAPI !== 'cli') {
            w_log_warning("WidgetScanner::scanWidget() 不应在 Web 运行时调用，请使用 WidgetData::getWidget()", [], 'WidgetScanner');
            return null;
        }
        
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

